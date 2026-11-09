<?php
// Web Push (notificacoes que chegam com o CRM fechado) sem bibliotecas: VAPID
// (RFC 8292, um JWT assinado com ES256) e a mensagem cifrada em aes128gcm
// (RFC 8291 / RFC 8188), tudo com o openssl do PHP. Porta de
// int.pombaldata.com/app/chat/webpush.php.
//
// Um par de chaves VAPID por instalacao (igual para todas as empresas, que
// partilham o mesmo dominio): VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY no .env
// (base64url: o ponto publico de 65 bytes e o PEM da chave privada) ou, sem
// elas, gerado uma vez em data/vapid-keys.php (PHP para nao ser servido como
// texto; fora do git). Mudar as chaves invalida as subscricoes existentes (o
// push.js renova-as sozinho na proxima visita).

const WEBPUSH_KEYS_FILE = __DIR__ . '/../data/vapid-keys.php';

function webpushB64urlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpushB64urlDecode(string $data): string {
    return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
}

/** Ponto P-256 nao comprimido (65 bytes) de uma chave EC do openssl. */
function webpushPublicPoint($key): string {
    $ec = (array) (openssl_pkey_get_details($key)['ec'] ?? []);
    return "\x04" . str_pad((string) ($ec['x'] ?? ''), 32, "\0", STR_PAD_LEFT) . str_pad((string) ($ec['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
}

/** Chave publica PEM (SubjectPublicKeyInfo) de um ponto P-256 nao comprimido. */
function webpushPublicPem(string $point): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function webpushNewKey() {
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) {
        throw new RuntimeException('Não foi possível gerar uma chave EC (openssl).');
    }
    return $key;
}

/**
 * Chaves VAPID da instalacao.
 * @return array{public:string,private_pem:string} public em base64url (applicationServerKey)
 */
function webpushVapid(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $public = trim((string) (getenv('VAPID_PUBLIC_KEY') ?: ''));
    $private = trim((string) (getenv('VAPID_PRIVATE_KEY') ?: ''));
    if ($public !== '' && $private !== '') {
        return $cache = ['public' => $public, 'private_pem' => webpushB64urlDecode($private)];
    }
    $data = is_file(WEBPUSH_KEYS_FILE) ? (include WEBPUSH_KEYS_FILE) : null;
    if (!is_array($data) || empty($data['public']) || empty($data['private_pem'])) {
        $key = webpushNewKey();
        openssl_pkey_export($key, $pem);
        $data = ['public' => webpushB64urlEncode(webpushPublicPoint($key)), 'private_pem' => (string) $pem];
        $php = "<?php\n// Chaves VAPID do Web Push (push/webpush.php). Geradas automaticamente; nao versionar.\n"
            . 'return ' . var_export($data, true) . ";\n";
        if (@file_put_contents(WEBPUSH_KEYS_FILE, $php, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível guardar as chaves VAPID em data/.');
        }
        @chmod(WEBPUSH_KEYS_FILE, 0600);
    }
    return $cache = ['public' => (string) $data['public'], 'private_pem' => (string) $data['private_pem']];
}

/** Assunto VAPID (contacto do servidor de aplicacao para os servicos de push). */
function webpushSubject(): string {
    $subject = trim((string) (getenv('VAPID_SUBJECT') ?: ''));
    return $subject !== '' ? $subject : 'https://aicrm.teknisoft.pt';
}

/** JWT ES256 para o servico de push de $endpoint (aud = a sua origem), valido 12 horas. */
function webpushJwt(string $endpoint, string $privatePem, string $subject): string {
    $parts = parse_url($endpoint);
    $aud = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . strtolower((string) ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $data = webpushB64urlEncode('{"typ":"JWT","alg":"ES256"}') . '.'
        . webpushB64urlEncode((string) json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($data, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Falha a assinar o JWT VAPID.');
    }
    // Assinatura ECDSA em DER (SEQUENCE de dois INTEGER) para o r||s do JWS ES256.
    $offset = 3 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
    $rLen = ord($der[$offset]);
    $r = substr($der, $offset + 1, $rLen);
    $sLen = ord($der[$offset + 2 + $rLen]);
    $s = substr($der, $offset + 3 + $rLen, $sLen);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $data . '.' . webpushB64urlEncode($raw);
}

/** Mensagem cifrada para uma subscricao (aes128gcm, um registo). $p256dh e $auth em base64url. */
function webpushEncrypt(string $payload, string $p256dh, string $auth): string {
    $uaPublic = webpushB64urlDecode($p256dh);
    $authSecret = webpushB64urlDecode($auth);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('Subscrição push inválida.');
    }
    $ephemeral = webpushNewKey();
    $asPublic = webpushPublicPoint($ephemeral);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(webpushPublicPem($uaPublic)), $ephemeral, 32);
    if ($shared === false) {
        throw new RuntimeException('Falha no ECDH da subscrição push.');
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Falha a cifrar a mensagem push.');
    }
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Envia um push. Devolve o estado HTTP do servico de push (201 = aceite;
 * 404/410 = a subscricao ja nao existe e deve ser apagada), 0 em erro de rede.
 *
 * @param array{endpoint:string,p256dh:string,auth:string} $subscription
 */
function webpushSend(array $subscription, array $payload, int $ttl = 86400): int {
    $endpoint = (string) $subscription['endpoint'];
    if (preg_match('#^https://#i', $endpoint) !== 1) {
        return 404;
    }
    $vapid = webpushVapid();
    $body = webpushEncrypt((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) $subscription['p256dh'], (string) $subscription['auth']);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: high',
            'Authorization: vapid t=' . webpushJwt($endpoint, $vapid['private_pem'], webpushSubject()) . ', k=' . $vapid['public'],
        ],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status;
}
