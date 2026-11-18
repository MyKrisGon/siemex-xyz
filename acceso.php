<?php
/**
 * SIEMEX · siemex.xyz/acceso.php — solicitudes de acceso al piloto y diagnóstico gratis
 *
 * 1. Valida y limpia los datos.
 * 2. Te manda un correo con la solicitud (y el enlace directo a WhatsApp).
 * 3. La guarda en data/accesos.csv (carpeta bloqueada al público).
 * Después, tú das de alta el negocio en plataforma.siemex.xyz (Admin → Negocios) y mandas la invitación.
 *
 * Responde JSON: { ok: true } o { ok: false, error: "..." }
 */

// ===================== CONFIGURACIÓN =====================
const NOTIFY_TO = 'contacto@desarrollo.siemex.xyz';   // a dónde llega el aviso
const MAIL_FROM = 'contacto@desarrollo.siemex.xyz';   // buzón que envía el aviso
const DATA_DIR  = __DIR__ . '/data';
const MAX_PER_HOUR_PER_IP = 5;
// =========================================================

date_default_timezone_set('America/Mazatlan');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// GET de "calentamiento" (para el anti-bot del hosting)
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode(['ok' => true]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }

function clean($v, $max = 300) {
  $v = is_string($v) ? $v : '';
  $v = trim(strip_tags($v));
  $v = preg_replace('/[\r\n\t]+/', ' ', $v);   // evita inyección de cabeceras
  return mb_substr($v, 0, $max);
}

// Honeypot: si un bot llenó el campo invisible, respondemos "ok" sin hacer nada
if (!empty($in['website'])) { echo json_encode(['ok' => true]); exit; }

$name     = clean($in['name'] ?? '', 120);
$business = clean($in['business'] ?? '', 200);
$city     = clean($in['city'] ?? '', 80);
$country  = strtoupper(clean($in['country'] ?? 'MX', 2));
$whatsapp = clean($in['whatsapp'] ?? '', 40);
$email    = clean($in['email'] ?? '', 160);
$site     = clean($in['site'] ?? '', 200);
$want     = clean($in['want'] ?? 'ambos', 20);
$consent  = !empty($in['consent']) ? 'sí' : 'no';
$lang     = (($in['lang'] ?? 'es') === 'en') ? 'en' : 'es';
$source   = clean($in['source'] ?? '', 300);

if (!in_array($country, ['MX', 'US', 'CA'], true)) { $country = 'MX'; }
if (!in_array($want, ['ambos', 'app', 'diagnostico'], true)) { $want = 'ambos'; }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }

$digits = preg_replace('/\D/', '', $whatsapp);
if ($name === '' || $business === '' || $city === '' || strlen($digits) < 10 || $consent !== 'sí') {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => $lang === 'en' ? 'Missing data.' : 'Faltan datos.']);
  exit;
}

// Teléfono en formato internacional según el país
if ($country === 'MX') {
  if (strlen($digits) === 10) { $phone = '+52' . $digits; }
  elseif (strlen($digits) === 13 && substr($digits, 0, 3) === '521') { $phone = '+52' . substr($digits, 3); }
  else { $phone = '+' . $digits; }
} else {
  $phone = (strlen($digits) === 10) ? '+1' . $digits : '+' . $digits;
}

// Límite simple por IP
if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0750, true); }
$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$rateFile = DATA_DIR . '/rate-' . md5($ip) . '.json';
$now = time();
$hits = is_file($rateFile) ? (json_decode(@file_get_contents($rateFile), true) ?: []) : [];
$hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
if (count($hits) >= MAX_PER_HOUR_PER_IP) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'error' => 'Demasiados envíos.']);
  exit;
}
$hits[] = $now;
@file_put_contents($rateFile, json_encode($hits), LOCK_EX);

$date = date('Y-m-d H:i');
$wantText = ['ambos' => 'La app y el diagnóstico', 'app' => 'Probar la app', 'diagnostico' => 'Solo el diagnóstico'][$want];

// Guardar en CSV (base de contactos con permiso)
$csv = DATA_DIR . '/accesos.csv';
$isNew = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
  flock($fh, LOCK_EX);
  if ($isNew) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['fecha', 'nombre', 'negocio', 'ciudad', 'pais', 'whatsapp', 'correo', 'sitio_o_instagram', 'interes', 'consentimiento', 'idioma', 'origen']); }
  fputcsv($fh, [$date, $name, $business, $city, $country, $phone, $email, $site, $wantText, $consent, $lang, $source]);
  flock($fh, LOCK_UN);
  fclose($fh);
}

// Correo de aviso
$waLink = 'https://wa.me/' . ltrim($phone, '+');
$subject = '=?UTF-8?B?' . base64_encode("Plataforma SIEMEX: $business ($name) · $wantText") . '?=';
$body  = "Nueva solicitud en siemex.xyz\n";
$body .= "=============================\n\n";
$body .= "Interés:    $wantText\n";
$body .= "Nombre:     $name\n";
$body .= "Negocio:    $business\n";
$body .= "Ciudad:     $city ($country)\n";
$body .= "WhatsApp:   $phone  ->  $waLink\n";
$body .= "Correo:     " . ($email ?: '-') . "\n";
$body .= "Sitio / IG: " . ($site ?: '-') . "\n";
$body .= "Idioma:     $lang\n";
$body .= "Origen:     $source\n";
$body .= "Fecha:      $date\n\n";
$body .= "Siguiente paso: diagnóstico en 48 h hábiles y, si hay lugar, invitación desde plataforma.siemex.xyz (Admin → Negocios).\n";
$headers  = "From: Plataforma SIEMEX <" . MAIL_FROM . ">\r\n";
if ($email !== '') { $headers .= "Reply-To: $email\r\n"; }
$headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
$mailOk = @mail(NOTIFY_TO, $subject, $body, $headers, '-f' . MAIL_FROM);

// Aunque el correo falle, la solicitud quedó guardada en el CSV
echo json_encode(['ok' => true, 'mail' => (bool)$mailOk]);
