<?php
// SIEMEX · siemex.xyz/encuesta
// Cuenta cuántas veces se toca "Contestar encuesta" por canal y día, y manda al formulario corto.
// No guarda IP, cookies ni ningún dato de la persona: solo fecha, canal y total.

$ENTRY = 'entry.869429802'; // "¿Cómo te enteraste del estudio?" / "How did you hear about this study?" (mismo id en los dos)
$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'es';
if ($lang === 'en') {
  $FORM = 'https://docs.google.com/forms/d/e/1FAIpQLSeN35KGp8zFaW7QblUbZfT-TohOKu_Yr5r-9WMz52NDHREGew/viewform';
  $CANALES = [
    'fb' => 'Facebook or Instagram',
    'wa' => 'Text message or WhatsApp',
    'li' => 'LinkedIn',
    'gr' => 'A Facebook group',
  ];
} else {
  $FORM = 'https://docs.google.com/forms/d/e/1FAIpQLSd3xw_77nCLOEFnAJXzAqXORhXQpqnccWVuN9rZ0nSNxzBE-Q/viewform';
  $CANALES = [
    'fb' => 'Facebook o Instagram',
    'wa' => 'WhatsApp',
    'li' => 'LinkedIn',
    'gr' => 'Grupo de Facebook',
  ];
}

$c = isset($_GET['c']) ? strtolower(preg_replace('/[^a-z]/i', '', $_GET['c'])) : '';
if (!isset($CANALES[$c])) { $c = 'web'; }

// Contador: .encuesta-clics.csv  (fecha,canal,total). Protegido en .htaccess.
$file = __DIR__ . '/.encuesta-clics.csv';
$fh = @fopen($file, 'c+');
if ($fh && flock($fh, LOCK_EX)) {
  $rows = [];
  while (($line = fgets($fh)) !== false) {
    $p = explode(',', trim($line));
    if (count($p) === 3 && $p[0] !== 'fecha') { $rows[$p[0] . ',' . $p[1]] = (int)$p[2]; }
  }
  $tz = new DateTimeZone('America/Mazatlan');
  $key = (new DateTime('now', $tz))->format('Y-m-d') . ',' . $lang . '-' . $c;
  $rows[$key] = ($rows[$key] ?? 0) + 1;
  ksort($rows);
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, "fecha,canal,total\n");
  foreach ($rows as $k => $n) { fwrite($fh, $k . ',' . $n . "\n"); }
  fflush($fh); flock($fh, LOCK_UN);
}
if ($fh) { fclose($fh); }

$url = $FORM . '?usp=pp_url';
if ($c !== 'web') { $url .= '&' . $ENTRY . '=' . rawurlencode($CANALES[$c]); }

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Location: ' . $url, true, 302);
exit;
