<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El descubrimiento OIDC y el canje del código.
 *
 * POR QUÉ NO ESTÁ SOCIALITE
 * -------------------------
 * Socialite resuelve el caso de una aplicación que entra sola. Esta aplicación
 * tiene más cosas: el grupo del token dice a qué estudio pertenece la persona, y
 * el rol se relee en cada ingreso. Con Socialite se podría, pero se terminaría
 * leyendo el descubrimiento de todos modos.
 *
 * LO QUE HACE
 * -----------
 *  1. Lee `<emisor>/.well-known/openid-configuration` y guarda lo que importa.
 *  2. Arma la URL de autorización con el `state` anti falsificación.
 *  3. Canjea el código por tokens con la clave del cliente.
 *  4. Trae las claves públicas para verificar la firma.
 *
 * TODO SE LEE DEL DESCUBRIMIENTO, NADA DE URLs ESCRITAS A MANO
 * ------------------------------------------------------------
 * Escribir `https://emisor/oauth2/token` a mano es la forma de que un cambio del
 * proveedor rompa el ingreso sin que nadie sepa por qué. Ver docs/05.
 */
class DescubridorOidc
{
    /** Los metadatos duran poco: si Authentik cambia de dominio, se nota hoy. */
    private const MINUTOS_DE_CACHE = 60;

    public function __construct(
        private readonly string $emisor,
        private readonly string $clienteId,
        private readonly string $clienteSecreto,
        private readonly string $redireccionUri,
    ) {}

    /**
     * La URL a la que va el navegador, sin redirigir todavía.
     *
     * La usa el controlador para validar el estudio antes de mandar a nadie a
     * Authentik: mandar a un estudio que no existe produce un error de vuelta que
     * nadie entiende.
     */
    public function urlDeAutorizacion(Request $peticion, string $estudioSlug = ''): string
    {
        $metadatos = $this->metadatos();

        $estado = bin2hex(random_bytes(16));

        $peticion->session()->put('oidc.estado', $estado);

        if ($estudioSlug !== '') {
            $peticion->session()->put('oidc.estudio', $estudioSlug);
        }

        $parametros = [
            'response_type' => 'code',
            'client_id' => $this->clienteId,
            'redirect_uri' => $this->redireccionUri,
            'scope' => 'openid profile email groups',
            'state' => $estado,
        ];

        return $metadatos['authorization_endpoint'] . '?' . http_build_query($parametros);
    }

    /**
     * Cambia el código de autorización por tokens.
     *
     * @return array<string, mixed>
     */
    public function intercambiarCodigoPorTokens(string $codigo): array
    {
        $metadatos = $this->metadatos();

        $respuesta = Http::asForm()->timeout(15)->post($metadatos['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $codigo,
            'redirect_uri' => $this->redireccionUri,
            'client_id' => $this->clienteId,
            'client_secret' => $this->clienteSecreto,
        ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException('Authentik no aceptó el canje del código de autorización.');
        }

        return (array) $respuesta->json();
    }

    /**
     * Las claves públicas con las que se verifica la firma del `id_token`.
     *
     * @return array<string, mixed>
     */
    public function clavesPublicas(): array
    {
        $metadatos = $this->metadatos();

        $respuesta = Http::timeout(15)->get($metadatos['jwks_uri']);

        if (! $respuesta->successful()) {
            throw new RuntimeException('No se pudieron obtener las claves públicas del proveedor.');
        }

        return (array) $respuesta->json();
    }

    /**
     * El descubrimiento, con caché.
     *
     * @return array<string, string>
     */
    public function metadatos(): array
    {
        if ($this->emisor === '') {
            throw new RuntimeException('Falta AUTHENTIK_ISSUER. Ver docs/05.');
        }

        /** @var array<string, string> $metadatos */
        $metadatos = Cache::remember(
            'fotografia:oidc:metadatos',
            now()->addMinutes(self::MINUTOS_DE_CACHE),
            function (): array {
                $respuesta = Http::timeout(15)->get(
                    rtrim($this->emisor, '/') . '/.well-known/openid-configuration'
                );

                if (! $respuesta->successful()) {
                    throw new RuntimeException('No se pudo leer el descubrimiento OIDC del proveedor.');
                }

                $cuerpo = (array) $respuesta->json();

                foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $obligatoria) {
                    if (! isset($cuerpo[$obligatoria])) {
                        throw new RuntimeException(
                            'El descubrimiento OIDC no trae `' . $obligatoria . '`. Revise el emisor configurado.'
                        );
                    }
                }

                return $cuerpo;
            }
        );

        return $metadatos;
    }
}