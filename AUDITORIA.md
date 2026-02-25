# Auditoría técnica – customclientpriceviewer

## Resumen ejecutivo
Se revisó el módulo con foco en seguridad, robustez y exactitud de cálculo de precio. Se encontraron y corrigieron riesgos de serialización insegura, validación de datos incompleta y un bug lógico en cálculo de impuestos.

## Hallazgos detectados

1. **Riesgo de deserialización insegura**
   - Antes se usaba `unserialize()` sobre configuración persistida.
   - Se migró a `json_encode/json_decode` para almacenar y recuperar grupos visibles.

2. **Validación débil de entrada en grupos visibles**
   - Antes se guardaban valores sin normalización estricta.
   - Ahora se convierten a enteros, se deduplican y se validan como arreglo.

3. **Comparaciones no estrictas de grupos**
   - Se usaba `in_array` sin modo estricto.
   - Se cambió a `in_array(..., true)` para evitar coincidencias por coerción de tipos.

4. **Bug en cálculo de precio con impuestos**
   - La variable `$use_tax` se sobrescribía siempre con `true` dentro de `Product::getPriceStatic(...)`.
   - Se corrigió para respetar el método de cálculo configurado para el cliente.

5. **Robustez del hook**
   - Se añadió validación de existencia de `type` en parámetros para evitar notices.

## Estado posterior
El módulo queda con menor superficie de riesgo y comportamiento más predecible en producción.
