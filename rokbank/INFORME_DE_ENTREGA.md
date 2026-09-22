# Informe de entrega · Ampliación de ROKBANK

Versión del esquema **3**. Implementación aditiva, compatible y reversible
sobre la instalación existente.

## 1. Copia de seguridad previa

La migración crea automáticamente una copia lógica completa con motivo
`before-schema-v3` **antes** de modificar nada, y sólo escribe
`schema_version = 3` cuando todos los pasos terminan correctamente. Si un
conteo o un total no coincide, se detiene, explica la diferencia y deja la
copia disponible.

Durante el desarrollo se verificó el camino real de actualización sobre una
base MariaDB 10.11 con el esquema anterior (versión 2), 11 jugadores, 18
aportes y un evento archivado en el formato antiguo.

## 2. Archivos creados

| Archivo | Contenido |
| --- | --- |
| `announcements.php` | Lista pública de comunicados |
| `announcement.php` | Comunicado completo |
| `media.php` | Entrega de medios con HTTP Range, ETag y control de borradores |
| `includes/announcements.php` | Modelo, marcado seguro, plantillas y ciclo editorial |
| `includes/media.php` | Validación, almacenamiento y recuento de referencias |
| `includes/admin_announcements.php` | Acciones administrativas de comunicados |
| `config.example.php` | Plantilla de `config.local.php` |
| `storage/media/.htaccess`, `storage/media/index.php` | Bloqueo de acceso y ejecución |
| `INFORME_DE_ENTREGA.md` | Este documento |

## 3. Archivos modificados

| Archivo | Cambios |
| --- | --- |
| `config.php` | Credenciales fuera del paquete, `config.local.php`, límites de medios, valores iniciales del evento |
| `includes/bootstrap.php` | Configuración dinámica, libro mayor de reservas, desglose por recurso, proyección sobre el fondo de premios, congelación, conciliación nueva, aportes tardíos, CSP |
| `includes/mysql.php` | Siete tablas nuevas, dieciséis columnas nuevas, migración idempotente a la versión 3, lectura y escritura del estado ampliado |
| `includes/layout.php` | Enlace público a Comunicados, editor de bloques, versión de caché |
| `admin.php` | Vistas Evento, Premio y reserva, Liquidación, Libro de reservas y Comunicados |
| `index.php` | Objetivo dinámico, desglose del fondo y la reserva, comunicado fijado |
| `history.php` | Métrica histórica por evento, reglas congeladas y fotografía contable |
| `export.php` | Bruto, neto, impuesto, reserva, reglas y metadatos de comunicados |
| `assets/app.js` | Editor por bloques, barra de formato y herramientas WebMCP |
| `assets/styles.css` | Comunicados, medios, paleta, editor y contabilidad |
| `.htaccess` | Bloqueo de `config.local.php` y `config.example.php` |
| `update_donations2.php`, `update_donations3.php` | Ahora exigen sesión administrativa |
| `LEEME_PRIMERO.txt` | Instalación, migración, límites, respaldo y flujo administrativo |

## 4. Decisiones contables

1. **Puntos básicos enteros.** El porcentaje se guarda como `0..10000` y se
   aplica con aritmética entera (`intdiv`), nunca con coma flotante.
2. **El residuo del redondeo se queda en la reserva.** `P_evento` usa
   `floor`; nunca se redondea hacia arriba.
3. **Separación por recurso.** Comida, madera, piedra y oro se calculan por
   separado y nunca se compensan entre sí.
4. **El libro mayor es la fuente de verdad.** El saldo de reserva se deriva de
   la suma de movimientos. No hay ninguna tabla de saldos editable.
5. **Sólo se anexa.** Un movimiento contabilizado no se edita ni se borra;
   las correcciones son movimientos compensatorios con justificación y
   auditoría.
6. **El movimiento del evento se contabiliza exactamente una vez** gracias a
   una clave de deduplicación única por evento, recurso y tipo.
7. **Sin reserva de apertura automática.** El saldo actual pertenece al evento
   activo y ya se cuenta como aporte; registrarlo también como reserva lo
   contaría dos veces.
8. **La lógica tributaria no cambia.** TOP 1 y TOP 2 siguen siendo netos
   garantizados y TOP 3 recibe el remanente. Con el 100 % y sin reserva, la
   proyección es idéntica byte a byte a la versión anterior.
9. **El cierre ya no exige saldo cero**, sino que el saldo físico coincida
   exactamente con `R_cierre`, recurso por recurso.

## 5. Decisiones de seguridad

1. **Nunca se guarda HTML de una persona.** El editor guarda JSON estructurado
   con bloques y marcas permitidas; el servidor genera el HTML con plantillas.
2. **Paleta de colores en vez de estilos en línea.** Los colores se traducen a
   clases CSS, de modo que la Content Security Policy sigue prohibiendo los
   estilos incrustados y el diseño enriquecido no reduce la seguridad.
3. **La CSP cambió lo mínimo:** `media-src 'none'` pasó a
   `media-src 'self' blob:`. Los scripts en línea siguen prohibidos.
4. **El tipo de archivo se detecta por contenido** con `finfo`, nunca por el
   nombre ni por lo que declare el navegador. SVG se rechaza siempre.
5. **Nombres generados por el servidor**, carpeta sin acceso ni ejecución y
   entrega exclusivamente a través de `media.php`.
6. **Los borradores no son públicos** aunque se adivine su dirección, y sus
   archivos sólo se sirven a una sesión administrativa.
7. **Recuento de referencias** antes de borrar un archivo compartido.
8. **Las credenciales salen del paquete** y pasan a variables de entorno o a
   `config.local.php`, que está bloqueado y excluido del repositorio.

## 6. Eventos históricos

Se comparó campo por campo un evento archivado antes y después de la
migración: **0 campos eliminados, 0 campos modificados** y 21 campos añadidos
con valores neutros. La métrica histórica se conserva como
«Fuertes destruidos» y no se reinterpreta con la configuración actual.
