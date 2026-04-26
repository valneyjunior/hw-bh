<?php
function generateId(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function generateTempPassword(int $len = 10): string {
    $c = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $c[random_int(0, strlen($c) - 1)];
    return $p;
}

function fmtDt(string $dt): string {
    return (new DateTime($dt))->format('d/m/Y H:i');
}

function fmtDuration(string $start, string $end): string {
    $s = (new DateTime($end))->getTimestamp() - (new DateTime($start))->getTimestamp();
    return sprintf('%02d:%02d', floor($s / 3600), floor(($s % 3600) / 60));
}

function sumDuration(array $records): string {
    $t = 0;
    foreach ($records as $r)
        $t += (new DateTime($r['ended_at']))->getTimestamp() - (new DateTime($r['started_at']))->getTimestamp();
    return sprintf('%02d:%02d', floor($t / 3600), floor(($t % 3600) / 60));
}

function minutesToHHMM(int|float $minutes): string {
    $m = (int)round($minutes);
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Calcula o valor financeiro do banco de horas com adicionais CLT:
 * - Adicional noturno 20% (Art. 73 CLT) para horas entre 22h e 5h
 * - Adicional 100% aos domingos (Art. 67 CLT + CF/88 Art. 7º, XV)
 * Base: salário ÷ 220h (regime 44h/semana)
 */
function calcBhValue(array $records, float $monthlySalary): array {
    $hourlyRate = $monthlySalary / 220.0;
    $mins = ['regular' => 0.0, 'noturno' => 0.0, 'domingo' => 0.0, 'domingo_noturno' => 0.0];

    foreach ($records as $r) {
        if (!$r['validated_at']) continue;

        $cur = new DateTime($r['started_at']);
        $end = new DateTime($r['ended_at']);

        while ($cur < $end) {
            // Avança até o próximo limite de hora
            $nextHour = new DateTime($cur->format('Y-m-d H:00:00'));
            $nextHour->modify('+1 hour');
            $seg = $nextHour > $end ? $end : $nextHour;

            $segMins = ($seg->getTimestamp() - $cur->getTimestamp()) / 60.0;
            $h   = (int)$cur->format('G');
            $dow = (int)$cur->format('w'); // 0 = domingo

            $isNight  = ($h >= 22 || $h < 5);
            $isSunday = ($dow === 0);

            if ($isSunday && $isNight)    $mins['domingo_noturno'] += $segMins;
            elseif ($isSunday)            $mins['domingo']         += $segMins;
            elseif ($isNight)             $mins['noturno']         += $segMins;
            else                          $mins['regular']         += $segMins;

            $cur = $seg;
        }
    }

    $value  = ($mins['regular']         / 60) * $hourlyRate * 1.0;
    $value += ($mins['noturno']         / 60) * $hourlyRate * 1.2;
    $value += ($mins['domingo']         / 60) * $hourlyRate * 2.0;
    $value += ($mins['domingo_noturno'] / 60) * $hourlyRate * 2.2;

    return [
        'hourlyRate'   => $hourlyRate,
        'totalValue'   => $value,
        'minutes'      => $mins,
        'totalMinutes' => array_sum($mins),
    ];
}

function jsonBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function jsonOut(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
