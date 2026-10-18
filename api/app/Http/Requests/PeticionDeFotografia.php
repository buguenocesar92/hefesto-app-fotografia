<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de las peticiones de Fotografía.
 *
 * POR QUÉ ES UNA CLASE SUELTA
 * ---------------------------
 * Por una razón práctica que se aprendió a la mala: en producción, sin el mapa de
 * clases optimizado, Laravel puede no encontrar una clase que no esté en el
 * `classmap`. El archivo se llama así por convención del proyecto; no es que
 * PSR-4 lo exija. Ver `deploy/api/Dockerfile`.
 *
 * LO QUE NO HACE
 * --------------
 * No autoriza. La autorización la hace la `Policy` del recurso, que responde
 * 404 cuando rechaza. Acá se valida la FORMA de lo que llega, no si la persona
 * puede hacer lo que está pidiendo. Ver docs/03.
 */
abstract class PeticionDeFotografia extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización es responsabilidad de la Policy del recurso. Ver la
        // nota del encabezado.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function mensajes(): array
    {
        return [
            'required' => 'Falta el campo :attribute.',
            'string' => 'El campo :attribute tiene que ser texto.',
            'integer' => 'El campo :attribute tiene que ser un número entero.',
            'numeric' => 'El campo :attribute tiene que ser un número.',
            'max' => [
                'string' => 'El campo :attribute es más largo de lo permitido.',
                'integer' => 'El campo :attribute es más grande de lo permitido.',
            ],
            'min' => [
                'string' => 'El campo :attribute es más corto de lo permitido.',
                'integer' => 'El campo :attribute es más pequeño de lo permitido.',
            ],
            'date' => 'El campo :attribute no es una fecha válida.',
            'email' => 'El campo :attribute no es un correo válido.',
            'boolean' => 'El campo :attribute tiene que ser sí o no.',
            'array' => 'El campo :attribute tiene que ser una lista.',
            'rut' => 'El campo :attribute no tiene la forma de un RUT chileno.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function atributos(): array
    {
        return [
            'nombre' => 'nombre',
            'apellido' => 'apellido',
            'rut' => 'RUT',
            'telefono' => 'teléfono',
            'correo' => 'correo',
            'tipo' => 'tipo',
            'titulo' => 'título',
            'lugar' => 'lugar',
            'fecha_prometida' => 'fecha prometida',
            'fecha_realizada' => 'fecha realizada',
            'fecha_prometida_entrega' => 'fecha prometida de entrega',
            'duracion_minutos' => 'duración en minutos',
            'cantidad_pactada' => 'cantidad pactada',
            'cantidad_entregada' => 'cantidad entregada',
            'precio_unitario' => 'precio unitario',
            'precio_total' => 'precio total',
            'concepto' => 'concepto',
            'monto' => 'monto',
            'metodo' => 'método de pago',
            'fecha' => 'fecha',
            'codigo' => 'código',
            'formato' => 'formato',
            'cantidad_pedida' => 'cantidad pedida',
            'cantidad_recibida' => 'cantidad recibida',
            'dias_promesa_entrega' => 'días de promesa de entrega',
            'conservacion_id' => 'conservación',
            'ubicacion' => 'ubicación',
            'tipo_medio' => 'tipo de medio',
            'estado' => 'estado',
            'seleccion' => 'selección',
        ];
    }

    /**
     * Deja los mensajes en español, también los que Laravel trae en inglés.
     *
     * Sin esto, un campo que falla por una regla no contemplada muestra
     * "The given data was invalid." y quien atiende no sabe qué corregir.
     */
    protected function failedValidation(Validator $validador): void
    {
        throw new \Illuminate\Validation\ValidationException($validador);
    }
}