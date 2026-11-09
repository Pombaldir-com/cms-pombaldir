<?php
// Sessao PHP so em memoria, para pedidos sem utilizador do browser (CalDAV,
// icones da app): getPDO() le a empresa de $_SESSION['company'], mas estes
// pedidos nao devem enviar cookie nem criar ficheiros de sessao.
// Chamar antes de functions.php iniciar qualquer sessao.

final class AppMemorySession implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}

function startMemorySession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cache_limiter', '');
    session_set_save_handler(new AppMemorySession(), true);
    session_id('mem' . bin2hex(random_bytes(8)));
    session_start();
}
