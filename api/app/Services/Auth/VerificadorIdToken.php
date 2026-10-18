<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use UnexpectedValueException;

/**
 * Verifica el `id_token` de Authentik.
 *
 * LO QUE SE VERIFICA, Y SIEMPRE
 * -----------------------------
 *   - La firma, con las claves que publica el propio emisor.
 *   - `iss`: el token tiene que ser de ESTE emisor.
 *   - `aud`: tiene que ser para ESTA aplicación.
 *   - `exp`: no puede estar vencido.
 *
 * No hay opción de apagar ninguna de las tres. Un token emitido para otra
 * aplicación del mismo emisor es un token que pasa la firma y falla el `aud`, y
 * si esa comprobación no estuviera, una cuenta de otra aplicación de Hefesto
 * entraría a este estudio. Ver docs/07.
 */
class VerificadorIdToken
{
    /**
     * @param  array<string, mixed>  $clavesPublicas  el JWKS completo
     */
    public function __construct(
        private readonly array $clavesPublicas,
        private readonly string $emisorEsperado,
        private readonly string $audienciaEsperada,
        private readonly int $margenDeTiempo = 60,
    ) {}

    /**
     * @return array<string, mixed> los claims verificados
     */
    public function verificar(string $idToken): array
    {
        if ($this->emisorEsperado === '') {
            throw new RuntimeException('Falta AUTHENTIK_ISSUER: no hay contra qué comparar el `iss`.');
        }

        $normalizado = rtrim($this->emisorEsperado, '/');

        try {
            $claves = JWK::parseKeySet($this->clavesPublicas);

            $claims = (array) JWT::decode(
                $idToken,
                $claves,
                [
                    'RS256',
                ]
            );
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException('El `id_token` no pasó la verificación: ' . $e->getMessage(), 0, $e);
        }

        $emisor = (string) ($claims['iss'] ?? '');

        if ($emisor !== $normalizado) {
            throw new RuntimeException('El `id_token` viene de otro emisor del que el configuró.');
        }

        $this->exigirAudiencia($claims);

        // `JWT::decode` ya revisó `exp`, con el margen de reloj de la librería.
        // Acá se vuelve a comprobar a mano para que el mensaje sea del dominio y
        // no un error de la librería que nadie entiende.
        $vencimiento = (int) ($claims['exp'] ?? 0);

        if ($vencimiento === 0) {
            throw new RuntimeException('El `id_token` no trae `exp`.');
        }

        if ($vencimiento + $this->margenDeTiempo < time()) {
            throw new RuntimeException('El `id_token` está vencido. Entre de nuevo.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function exigirAudiencia(array $claims): void
    {
        $esperada = $this->audienciaEsperada;

        if ($esperada === '') {
            throw new RuntimeException('Falta AUTHENTIK_CLIENT_ID: no hay contra qué comparar el `aud`.');
        }

        $audiencia = $claims['aud'] ?? null;

        // `aud` puede venir como texto o como lista, según el proveedor.
        $valores = is_array($audiencia) ? array_map('strval', $audiencia) : [(string) $audiencia];

        if (! in_array($esperada, $valores, true)) {
            throw new RuntimeException('El `id_token` no es para esta aplicación.');
        }
    }
}