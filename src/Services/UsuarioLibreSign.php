<?php

namespace iEducar\Packages\BuritiAssinaturaDigital\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolve o usuário i-Educar (regras do documento) para conta LibreSign/Nextcloud.
 */
final class UsuarioLibreSign
{
    /**
     * @param  array<string,mixed>  $assinatura  nome/cargo/login/user_id/account/email
     * @return array{account:?string,email:?string,password:?string,displayName:string,login:?string,user_id:?int}
     */
    public function resolver(array $assinatura = []): array
    {
        $nome = trim((string) ($assinatura['nome'] ?? $assinatura['displayName'] ?? ''));
        $cargo = trim((string) ($assinatura['cargo'] ?? ''));
        $account = trim((string) ($assinatura['account'] ?? ''));
        $email = trim((string) ($assinatura['email'] ?? ''));
        $login = trim((string) ($assinatura['login'] ?? ''));
        $userId = (int) ($assinatura['user_id'] ?? $assinatura['responsavel_id'] ?? 0);
        $password = trim((string) ($assinatura['password'] ?? ''));

        $portal = $this->dadosPortal($userId > 0 ? $userId : null, $login !== '' ? $login : null);
        if ($login === '' && $portal['login'] !== null) {
            $login = $portal['login'];
        }
        if ($email === '' && $portal['email'] !== null) {
            $email = $portal['email'];
        }
        if ($nome === '' && $portal['nome'] !== null) {
            $nome = $portal['nome'];
        }
        if ($userId <= 0 && $portal['id'] !== null) {
            $userId = $portal['id'];
        }

        // Usuário autenticado (quem assinou/emitiu no i-Educar), se a assinatura não trouxe identidade.
        if ($login === '' && $email === '' && $account === '') {
            $auth = $this->usuarioAutenticado();
            if ($auth !== null) {
                $login = $auth['login'] ?? '';
                $email = $email !== '' ? $email : (string) ($auth['email'] ?? '');
                $nome = $nome !== '' ? $nome : (string) ($auth['nome'] ?? '');
                $userId = $userId > 0 ? $userId : (int) ($auth['id'] ?? 0);
            }
        }

        if ($account === '' && $login !== '') {
            $account = $this->accountPorLogin($login);
        }
        if ($account === '' && $email !== '') {
            $mapped = $this->accountPorLogin($email);
            if ($mapped !== '') {
                $account = $mapped;
            }
        }

        if ($password === '' && $account !== '') {
            $password = $this->senhaDaConta($account);
        }

        return [
            'account' => $account !== '' ? $account : null,
            'email' => $email !== '' ? $email : null,
            'password' => $password !== '' ? $password : null,
            'displayName' => $nome !== '' ? $nome : ($login !== '' ? $login : ($account !== '' ? $account : $cargo)),
            'login' => $login !== '' ? $login : null,
            'user_id' => $userId > 0 ? $userId : null,
            'cargo' => $cargo,
            'nome' => $nome,
        ];
    }

    /**
     * Snapshot do usuário logado (portal).
     *
     * @return array{id:int,login:?string,email:?string,nome:?string}|null
     */
    public function usuarioAutenticado(): ?array
    {
        $u = Auth::user();
        if ($u === null) {
            return null;
        }

        $login = trim((string) ($u->login ?? ''));
        $email = trim((string) ($u->email ?? ''));
        $nome = trim((string) ($u->name ?? $u->nome ?? ''));

        // i-Educar clássico: login/email no employee.
        try {
            if ($login === '' && method_exists($u, 'getAttribute')) {
                $login = trim((string) ($u->getAttribute('login') ?? ''));
            }
            if (($login === '' || $email === '') && isset($u->employee)) {
                $login = $login !== '' ? $login : trim((string) ($u->employee->login ?? ''));
                $email = $email !== '' ? $email : trim((string) ($u->employee->email ?? ''));
            }
        } catch (Throwable) {
            //
        }

        return [
            'id' => (int) ($u->getAuthIdentifier() ?? 0),
            'login' => $login !== '' ? $login : null,
            'email' => $email !== '' ? $email : null,
            'nome' => $nome !== '' ? $nome : null,
        ];
    }

    /**
     * login i-Educar → conta LibreSign.
     * Ordem: mapa explícito → conta signer conhecida com mesmo nome → (opcional) o próprio login.
     */
    public function accountPorLogin(string $login): string
    {
        $login = trim($login);
        if ($login === '') {
            return '';
        }

        $mapa = $this->mapaUsuarios();
        $chave = mb_strtolower($login);
        if (isset($mapa[$chave]) && $mapa[$chave] !== '') {
            return $mapa[$chave];
        }

        foreach ([1, 2, 3, 4] as $n) {
            $acc = trim((string) config('assinatura.libresign.signer'.$n, ''));
            if ($acc !== '' && strcasecmp($acc, $login) === 0) {
                return $acc;
            }
        }

        if ((bool) config('assinatura.usar_login_como_conta', true)) {
            return $login;
        }

        return '';
    }

    public function senhaDaConta(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '';
        }

        foreach ([1, 2, 3, 4] as $n) {
            $acc = trim((string) config('assinatura.libresign.signer'.$n, ''));
            if ($acc !== '' && strcasecmp($acc, $account) === 0) {
                return trim((string) config('assinatura.libresign.signer'.$n.'_password', ''));
            }
        }

        $mapaSenhas = (array) config('assinatura.mapa_usuarios_senhas', []);
        $k = mb_strtolower($account);
        if (isset($mapaSenhas[$k]) && trim((string) $mapaSenhas[$k]) !== '') {
            return trim((string) $mapaSenhas[$k]);
        }

        // Também aceita chave = login i-Educar no mapa de senhas.
        foreach ($this->mapaUsuarios() as $login => $accMap) {
            if (strcasecmp((string) $accMap, $account) === 0) {
                $pwd = trim((string) ($mapaSenhas[mb_strtolower((string) $login)] ?? ''));
                if ($pwd !== '') {
                    return $pwd;
                }
            }
        }

        return '';
    }

    /**
     * Credenciais para clickToSign: conta do slot + fallback cargo/signer (igual ao orquestrador).
     *
     * @param  array<string,mixed>  $slot
     * @return array{account:string,password:string,via:string}
     */
    public function credenciaisParaAssinar(array $slot, ?string $senhaInformada = null): array
    {
        $senhaInformada = trim((string) $senhaInformada);
        $resolvido = $this->resolver($slot);
        $account = trim((string) ($resolvido['account'] ?? ''));
        $password = $senhaInformada !== ''
            ? $senhaInformada
            : trim((string) ($resolvido['password'] ?? ''));
        $via = $senhaInformada !== '' ? 'informada' : 'mapa';

        $cargo = trim((string) ($slot['cargo'] ?? $resolvido['cargo'] ?? ''));
        $accountCargo = $this->accountPorCargo($cargo);

        if ($password === '' && $account !== '') {
            $password = $this->senhaDaConta($account);
        }

        // Conta = login do portal sem senha: senha da conta do cargo (mantém account do pedido
        // quando já é signer*; só troca se ainda for login sem senha).
        if ($password === '' && $accountCargo !== '') {
            $pwdCargo = $this->senhaDaConta($accountCargo);
            if ($pwdCargo !== '') {
                if ($account === '' || strcasecmp($account, $accountCargo) === 0) {
                    $account = $accountCargo;
                    $password = $pwdCargo;
                    $via = 'cargo:'.$cargo;
                } elseif ($this->senhaDaConta($account) === '') {
                    // Pedido LibreSign provavelmente usou a conta do cargo na criação.
                    $account = $accountCargo;
                    $password = $pwdCargo;
                    $via = 'cargo:'.$cargo;
                }
            }
        }

        // Sem conta ainda: usa signer do cargo ou signer1 com senha.
        if ($account === '' || $password === '') {
            if ($accountCargo !== '') {
                $pwdCargo = $this->senhaDaConta($accountCargo);
                if ($pwdCargo !== '') {
                    $account = $accountCargo;
                    $password = $pwdCargo;
                    $via = 'cargo:'.$cargo;
                }
            }
        }

        return [
            'account' => $account,
            'password' => $password,
            'via' => $via,
        ];
    }

    public function accountPorCargo(string $cargo): string
    {
        $cargo = trim($cargo);
        if ($cargo === '') {
            return '';
        }
        $chave = mb_strtolower(preg_replace('/\s+/u', ' ', $cargo) ?? $cargo);
        $mapa = (array) config('assinatura.mapa_cargos', []);
        foreach ($mapa as $rotulo => $account) {
            $k = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $rotulo)) ?? '');
            if ($k !== '' && $k === $chave) {
                return trim((string) $account);
            }
        }

        return '';
    }

    /** @return array<string,string> login_lower => account */
    public function mapaUsuarios(): array
    {
        $raw = (array) config('assinatura.mapa_usuarios', []);
        $out = [];
        foreach ($raw as $login => $account) {
            $l = mb_strtolower(trim((string) $login));
            $a = trim((string) $account);
            if ($l !== '' && $a !== '') {
                $out[$l] = $a;
            }
        }

        return $out;
    }

    /**
     * @return array{id:?int,login:?string,email:?string,nome:?string}
     */
    private function dadosPortal(?int $userId, ?string $login): array
    {
        $vazio = ['id' => null, 'login' => null, 'email' => null, 'nome' => null];
        try {
            if (! Schema::hasTable('portal.funcionario')) {
                return $vazio;
            }
            $q = DB::table('portal.funcionario as f')
                ->leftJoin('cadastro.pessoa as p', 'p.idpes', '=', 'f.ref_cod_pessoa_fj');
            if ($userId !== null && $userId > 0) {
                $q->where(function ($w) use ($userId): void {
                    $w->where('f.ref_cod_pessoa_fj', $userId)
                        ->orWhereExists(function ($u) use ($userId): void {
                            $u->selectRaw('1')
                                ->from('pmieducar.usuario as us')
                                ->whereColumn('us.cod_usuario', 'f.ref_cod_pessoa_fj')
                                ->where('us.cod_usuario', $userId);
                        });
                });
            } elseif ($login !== null && $login !== '') {
                $q->whereRaw('lower(f.matricula) = ?', [mb_strtolower($login)]);
            } else {
                return $vazio;
            }
            $row = $q->selectRaw('f.ref_cod_pessoa_fj as id, f.matricula as login, f.email, p.nome')
                ->orderBy('f.ref_cod_pessoa_fj')
                ->first();
            if (! $row) {
                return $vazio;
            }

            return [
                'id' => isset($row->id) ? (int) $row->id : null,
                'login' => isset($row->login) && trim((string) $row->login) !== '' ? trim((string) $row->login) : null,
                'email' => isset($row->email) && trim((string) $row->email) !== '' ? trim((string) $row->email) : null,
                'nome' => isset($row->nome) && trim((string) $row->nome) !== '' ? trim((string) $row->nome) : null,
            ];
        } catch (Throwable) {
            return $vazio;
        }
    }
}
