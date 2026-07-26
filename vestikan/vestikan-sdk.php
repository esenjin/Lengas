<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  Vestikan — SDK satellite (fichier unique à inclure)
 * ============================================================================
 *
 *  Ajoute « Se connecter avec Vestikan » à un site. Un seul fichier, aucune
 *  dépendance (PHP + curl). Compatible PHP 8.1+.
 *
 *  UTILISATION MINIMALE
 *  --------------------
 *    require __DIR__ . '/vestikan-sdk.php';
 *
 *    $vk = new Vestikan([
 *        'base_url'      => 'https://concepts.esenjin.xyz/vestikan',
 *        'client_id'     => 'vk_client_xxxxxxxxxxxxxxxx',
 *        'client_secret' => '...(64 hex)...',
 *        'redirect_uri'  => 'https://concepts.esenjin.xyz/site1/vestikan-callback.php',
 *    ]);
 *
 *  Page « bouton de connexion » :
 *    $vk->begin();          // redirige vers Vestikan (ne revient pas)
 *
 *  Page de callback (redirect_uri) :
 *    $vestikanId = $vk->complete();   // renvoie le vestikan_id, ou lève une exception
 *
 *  À partir de $vestikanId, le SITE décide quel compte local ouvrir
 *  (voir patterns A et B plus bas et dans INTEGRATION.md).
 *
 *  SÉCURITÉ INTÉGRÉE
 *  -----------------
 *   - state anti-CSRF généré et vérifié automatiquement (stocké en session) ;
 *   - échange du code en back-channel (le client_secret ne transite jamais
 *     par le navigateur) ;
 *   - vérification TLS stricte sur l'appel /token ;
 *   - le SDK n'écrit aucun cookie propre : il s'appuie sur la session PHP
 *     existante du satellite pour le state.
 *
 *  Ce SDK ne dépend d'AUCUN e-mail (le pattern « matching par e-mail » n'existe
 *  pas côté Vestikan). La liaison est toujours explicite.
 * ============================================================================
 */

/**
 * Exception levée par le SDK en cas d'échec du flow (state invalide, code
 * refusé, réponse inattendue, erreur réseau…). Le message est concis ;
 * les détails techniques vont dans le log serveur du satellite.
 */
class VestikanException extends \RuntimeException {}

final class Vestikan
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $sessionKey;

    /**
     * @param array{
     *   base_url:string, client_id:string, client_secret:string,
     *   redirect_uri:string, session_key?:string
     * } $config
     */
    public function __construct(array $config)
    {
        foreach (['base_url', 'client_id', 'client_secret', 'redirect_uri'] as $req) {
            if (empty($config[$req])) {
                throw new VestikanException("Configuration Vestikan incomplète : '$req' manquant.");
            }
        }
        $this->baseUrl      = rtrim($config['base_url'], '/');
        $this->clientId     = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->redirectUri  = $config['redirect_uri'];
        // Clé de session où stocker le state (personnalisable si collision).
        $this->sessionKey   = $config['session_key'] ?? 'vestikan_state';
    }

    /* ================================================================== */
    /*  Étape 1 — démarrer le flow                                         */
    /* ================================================================== */

    /**
     * Redirige le navigateur vers l'écran d'autorisation de Vestikan.
     * Génère et mémorise un `state` anti-CSRF. Ne revient jamais (exit).
     *
     * @param string|null $returnTo URL de retour applicative à mémoriser
     *                    (ex. la page où l'utilisateur voulait aller). Elle
     *                    sera disponible via popReturnTo() après complete().
     */
    public function begin(?string $returnTo = null): never
    {
        $this->ensureSession();

        $state = bin2hex(random_bytes(16));
        $_SESSION[$this->sessionKey] = [
            'state'     => $state,
            'return_to' => $returnTo,
            'created'   => time(),
        ];

        $url = $this->baseUrl . '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
        ]);

        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Variante non-terminale : renvoie l'URL d'autorisation au lieu de
     * rediriger, si le satellite veut gérer la redirection lui-même
     * (ex. bouton HTML avec href).
     */
    public function authorizeUrl(?string $returnTo = null): string
    {
        $this->ensureSession();
        $state = bin2hex(random_bytes(16));
        $_SESSION[$this->sessionKey] = [
            'state'     => $state,
            'return_to' => $returnTo,
            'created'   => time(),
        ];
        return $this->baseUrl . '/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'state'         => $state,
        ]);
    }

    /* ================================================================== */
    /*  Étape 2 — traiter le retour (callback)                             */
    /* ================================================================== */

    /**
     * À appeler sur la page de callback (redirect_uri). Vérifie le state,
     * échange le code contre l'identité, et renvoie le vestikan_id.
     *
     * @return string Le vestikan_id (identifiant maître stable).
     * @throws VestikanException si le flow échoue (à traiter comme un refus
     *         d'authentification : ne PAS ouvrir de session locale).
     */
    public function complete(): string
    {
        $this->ensureSession();

        // 1) Erreur renvoyée par Vestikan (ex. response_type non supporté).
        if (isset($_GET['error'])) {
            $this->clearState();
            throw new VestikanException('Vestikan a renvoyé une erreur : '
                . preg_replace('/[^a-z_]/', '', (string) $_GET['error']));
        }

        // 2) Présence du code et du state.
        $code  = isset($_GET['code'])  && is_string($_GET['code'])  ? $_GET['code']  : null;
        $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : null;
        if ($code === null || $state === null) {
            $this->clearState();
            throw new VestikanException('Réponse incomplète (code ou state manquant).');
        }

        // 3) Vérification du state anti-CSRF (temps constant).
        $saved = $_SESSION[$this->sessionKey] ?? null;
        $this->clearState(); // usage unique : on l'efface quoi qu'il arrive.
        if (!is_array($saved) || !isset($saved['state'])
            || !hash_equals((string) $saved['state'], $state)) {
            throw new VestikanException('State invalide (possible CSRF ou session expirée).');
        }

        // 4) Échange back-channel du code contre le vestikan_id.
        $vestikanId = $this->exchange($code);

        // 5) On mémorise le return_to pour popReturnTo(), puis on renvoie l'id.
        $this->lastReturnTo = is_string($saved['return_to'] ?? null) ? $saved['return_to'] : null;

        return $vestikanId;
    }

    private ?string $lastReturnTo = null;

    /**
     * Récupère (une fois) l'URL de retour applicative passée à begin().
     * Renvoie null si aucune n'avait été fournie.
     */
    public function popReturnTo(): ?string
    {
        $v = $this->lastReturnTo;
        $this->lastReturnTo = null;
        return $v;
    }

    /* ================================================================== */
    /*  Échange back-channel                                               */
    /* ================================================================== */

    /**
     * Appelle /token en POST et renvoie le vestikan_id.
     * Le client_secret ne quitte jamais le serveur satellite.
     */
    private function exchange(string $code): string
    {
        $post = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
        ]);

        $ch = curl_init($this->baseUrl . '/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            error_log("[Vestikan SDK] échec réseau /token : $errmsg");
            throw new VestikanException('Impossible de joindre Vestikan.');
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            error_log("[Vestikan SDK] réponse /token illisible : " . substr((string) $body, 0, 200));
            throw new VestikanException('Réponse Vestikan invalide.');
        }

        if ($status !== 200 || !isset($data['vestikan_id'])) {
            $err = isset($data['error']) ? (string) $data['error'] : "http_$status";
            throw new VestikanException("Échange refusé par Vestikan ($err).");
        }

        $vid = (string) $data['vestikan_id'];
        // Sanity check : le vestikan_id est un identifiant hex court et stable.
        if (!preg_match('/^[a-f0-9]{8,64}$/', $vid)) {
            throw new VestikanException('Identifiant Vestikan de forme inattendue.');
        }
        return $vid;
    }

    /* ================================================================== */
    /*  Aides à la liaison de comptes (pattern B)                          */
    /* ================================================================== */
    /*
     * Ces aides sont FACULTATIVES et fournissent une implémentation SQLite
     * clé en main du pattern B (liaison vestikan_id -> compte local). Un site
     * qui gère déjà sa propre table peut les ignorer et faire sa liaison.
     *
     * Pattern A (site sans base) : ne rien utiliser ici. Après complete(),
     * un vestikan_id valide suffit à ouvrir l'accès (cf. INTEGRATION.md).
     */

    /**
     * Prépare la table de liaison dans une base SQLite du satellite.
     * Idempotent. $pdo doit être une connexion PDO SQLite du site.
     */
    public static function setupLinkTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS vestikan_links (
                vestikan_id   TEXT PRIMARY KEY,
                local_user_id TEXT NOT NULL,
                created_at    INTEGER NOT NULL
            )'
        );
    }

    /**
     * Crée une liaison vestikan_id -> identifiant de compte local.
     * À appeler UNE fois, après que l'utilisateur s'est authentifié
     * NATIVEMENT sur le site puis a cliqué « lier mon compte Vestikan ».
     *
     * @throws VestikanException si le vestikan_id est déjà lié.
     */
    public static function link(\PDO $pdo, string $vestikanId, string $localUserId): void
    {
        self::setupLinkTable($pdo);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO vestikan_links (vestikan_id, local_user_id, created_at)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$vestikanId, $localUserId, time()]);
        } catch (\PDOException $e) {
            throw new VestikanException('Ce compte Vestikan est déjà lié.');
        }
    }

    /**
     * Résout un vestikan_id vers l'identifiant de compte local lié, ou null
     * si aucune liaison n'existe (l'utilisateur doit alors se connecter
     * nativement puis lier).
     */
    public static function resolveLocalUser(\PDO $pdo, string $vestikanId): ?string
    {
        self::setupLinkTable($pdo);
        $stmt = $pdo->prepare(
            'SELECT local_user_id FROM vestikan_links WHERE vestikan_id = ?'
        );
        $stmt->execute([$vestikanId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (string) $row['local_user_id'] : null;
    }

    /**
     * Supprime une liaison (ex. « délier mon compte Vestikan »).
     */
    public static function unlink(\PDO $pdo, string $vestikanId): bool
    {
        self::setupLinkTable($pdo);
        $stmt = $pdo->prepare('DELETE FROM vestikan_links WHERE vestikan_id = ?');
        $stmt->execute([$vestikanId]);
        return $stmt->rowCount() === 1;
    }

    /* ================================================================== */
    /*  Interne                                                            */
    /* ================================================================== */

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private function clearState(): void
    {
        unset($_SESSION[$this->sessionKey]);
    }
}
