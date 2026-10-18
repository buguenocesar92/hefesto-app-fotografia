<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Alta y edición de una persona de la que son las fotos.
 *
 * POR QUÉ EL RUT SE VALIDA AQUÍ Y NO EN LA BASE
 * --------------------------------------------
 * Porque la base no sabe de Chile. El RUT es un dato del negocio, y su regla
 * pertenece al dominio. Además se guarda SIN puntos ni guion, para que
 * "12.345.678-9" y "123456789" no sean dos personas distintas. Ver docs/02.
 *
 * `notas` ES UN TEXTO LIBRE A PROPÓSITO
 * ------------------------------------
 * Sirve para lo que el estudio sepa y no tenga dónde poner: un apodo, dónde se
 * conoce a la persona. Lo que NO es un texto libre es la conservación de los
 * originales: eso tiene tabla, estado y revisión. Ver docs/08.
 * los originales: eso tiene tabla, estado y revisión. Ver docs/08.
 */
class GuardarClienteRequest extends PeticionDeFotografia
{
    /** @return array<string, mixed> */
    public function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
            'apellido' => ['required', 'string', 'max:160'],

            // `nullable` porque no todos dan RUT. `rut` es una regla propia,
            // declarada abajo, y por eso el mensaje está en `mensajes()`.
            'rut' => ['nullable', 'string', 'max:16', 'rut'],

            'telefono' => ['nullable', 'string', 'max:32'],
            'correo' => ['nullable', 'email', 'max:160'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function mensajes(): array
    {
        return parent::mensajes() + [
            'rut.rut' => 'El RUT no tiene la forma de uno chileno: se escriben los números y la letra, sin puntos.',
        ];
    }

    /**
     * El RUT se guarda limpio, sin puntos ni guion.
     *
     * Se limpia acá y no en el controlador para que no haya dos caminos de
     * escritura: si un día alguien escribe el RUT desde otro lado, la limpieza
     * se puede saltar.
     */
    protected function prepareForValidation(): void
    {
        $rut = $this->input('rut');

        if (! is_string($rut)) {
            return;
        }

        $this->merge(['rut' => self::limpiarRut($rut)]);
    }

    /** Deja solo dígitos y la letra, en mayúscula. */
    public static function limpiarRut(string $rut): string
    {
        return mb_strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));
    }

    /**
     * ¿Tiene la forma de un RUT chileno?
     *
     * Verifica los dígitos y el dígito verificador con el mismo módulo que usa
     * el Registro Civil. Un RUT con la forma correcta pero mal calculado es peor
     * que uno vacío: se escribe, queda en el sistema y después no se encuentra.
     */
    public function rutValido(string $rut): bool
    {
        if (! preg_match('/^([0-9]{7,8})([0-9K])$/u', $rut, $partes) === 1) {
            return false;
        }

        $cuerpo = $partes[1];
        $verificador = $partes[2] === 'K' ? 10 : (int) $partes[2];

        $suma = 0;
        $multiplicador = 2;

        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += (int) $cuerpo[$i] * $multiplicador;
            $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
        }

        $resto = 11 - ($suma % 11);

        return match ($resto) {
            11 => $verificador === 0,
            10 => $verificador === 10,
            default => $verificador === $resto,
        };
    }
}