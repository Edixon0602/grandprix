<?php
declare(strict_types=1);

final class SecretBox
{
    private string $keyPath;

    public function __construct(?string $keyPath = null)
    {
        $this->keyPath = $keyPath ?: dirname(__DIR__) . '/config/command.key';
    }

    public function ensureKey(): void
    {
        if (is_file($this->keyPath) && filesize($this->keyPath) === 32) return;
        $directory = dirname($this->keyPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('La carpeta config no permite crear la llave de comandos.');
        }
        $temporary = tempnam($directory, 'command-key-');
        if ($temporary === false || file_put_contents($temporary, random_bytes(32), LOCK_EX) !== 32) {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            throw new RuntimeException('No fue posible generar la llave de comandos.');
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->keyPath)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible publicar la llave de comandos.');
        }
    }

    public function encrypt(string $plain): string
    {
        $this->ensureKey();
        $key = $this->key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('El servidor necesita Sodium u OpenSSL para proteger las credenciales GPS.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('No fue posible cifrar la credencial GPS.');
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $key = $this->key();
        [$version, $payload] = array_pad(explode(':', $encoded, 2), 2, '');
        $raw = base64_decode($payload, true);
        if ($raw === false) throw new RuntimeException('La credencial GPS guardada no es valida.');
        if ($version === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = sodium_crypto_secretbox_open(substr($raw, $nonceLength), substr($raw, 0, $nonceLength), $key);
            if ($plain === false) throw new RuntimeException('No fue posible descifrar la credencial GPS.');
            return $plain;
        }
        if ($version === 'o1' && function_exists('openssl_decrypt')) {
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) throw new RuntimeException('No fue posible descifrar la credencial GPS.');
            return $plain;
        }
        throw new RuntimeException('El servidor no dispone del motor criptografico utilizado por la credencial.');
    }

    private function key(): string
    {
        if (!is_file($this->keyPath)) throw new RuntimeException('Falta la llave protegida de comandos GPS.');
        $key = (string) file_get_contents($this->keyPath);
        if (strlen($key) !== 32) throw new RuntimeException('La llave protegida de comandos GPS es invalida.');
        return $key;
    }
}
