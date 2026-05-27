<?php

// ── Formatação ────────────────────────────────────────────────────────────────

function fmtDt(string $dt): string {
    return (new DateTime($dt))->format('d/m/Y H:i');
}

function fmtDate(string $d): string {
    return (new DateTime($d))->format('d/m/Y');
}

function minutesToHHMM(int|float $minutes): string {
    $m = (int)round(abs($minutes));
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function fmtDuration(string $start, string $end): string {
    $s = (new DateTime($end))->getTimestamp() - (new DateTime($start))->getTimestamp();
    if ($s < 0) $s = 0;
    return sprintf('%02d:%02d', floor($s / 3600), floor(($s % 3600) / 60));
}


function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmtBRL(float $v): string {
    return 'R$\u{00a0}' . number_format($v, 2, ',', '.');
}

// ── Cálculo de data/hora dos lançamentos ─────────────────────────────────────

/**
 * Reconstrói os datetimes de início e fim a partir de data + hora.
 * Se hora_fim <= hora_inicio, assume que o término é no dia seguinte.
 */
function lancamentoDatetimes(string $data, string $horaInicio, string $horaFim): array {
    $start = new DateTime($data . ' ' . $horaInicio);
    $end   = new DateTime($data . ' ' . $horaFim);
    if ($end <= $start) {
        $end->modify('+1 day');
    }
    return [$start, $end];
}

function totalMinutosLancamento(string $data, string $horaInicio, string $horaFim): int {
    [$start, $end] = lancamentoDatetimes($data, $horaInicio, $horaFim);
    return (int)(($end->getTimestamp() - $start->getTimestamp()) / 60);
}

function foraDoPrazo(string $data, string $horaFim): bool {
    [, $end] = lancamentoDatetimes($data, '00:00', $horaFim);
    return (time() - $end->getTimestamp()) > (48 * 3600);
}

// ── Cálculo CLT de valor financeiro ─────────────────────────────────────────

/**
 * Calcula o valor do banco de horas com adicionais CLT:
 *  - Dia útil (seg-sáb): hora extra a 50% (valor/hora × 1,50)
 *  - Domingo ou feriado: hora extra a 100% (valor/hora × 2,00)
 *  - Adicional noturno 22h–05h: +20% (acumulável)
 * Base: salário ÷ 220h
 */
function calcBhValue(array $lancamentos, float $salarioBruto, float $adicionaAttrativo = 0.0): array {
    $hourlyRate = $salarioBruto / 220.0;
    $mins = [
        'util'           => 0.0,
        'util_noturno'   => 0.0,
        'domingo'        => 0.0,
        'domingo_noturno'=> 0.0,
        'feriado'        => 0.0,
        'feriado_noturno'=> 0.0,
    ];

    foreach ($lancamentos as $l) {
        if ($l['status'] !== 'aprovado') continue;

        [$cur, $end] = lancamentoDatetimes($l['data_acionamento'], $l['hora_inicio'], $l['hora_fim']);
        $isFeriado = (bool)$l['feriado'];

        while ($cur < $end) {
            $nextHour = new DateTime($cur->format('Y-m-d H:00:00'));
            $nextHour->modify('+1 hour');
            $seg = $nextHour > $end ? $end : $nextHour;

            $segMins = ($seg->getTimestamp() - $cur->getTimestamp()) / 60.0;
            $h   = (int)$cur->format('G');
            $dow = (int)$cur->format('w'); // 0=domingo

            $isNight  = ($h >= 22 || $h < 5);
            $isDomingo = ($dow === 0);

            if ($isFeriado && $isNight)      $mins['feriado_noturno']  += $segMins;
            elseif ($isFeriado)              $mins['feriado']           += $segMins;
            elseif ($isDomingo && $isNight)  $mins['domingo_noturno']   += $segMins;
            elseif ($isDomingo)              $mins['domingo']            += $segMins;
            elseif ($isNight)               $mins['util_noturno']       += $segMins;
            else                            $mins['util']               += $segMins;

            $cur = $seg;
        }
    }

    // Multiplicadores CLT
    $value  = ($mins['util']            / 60) * $hourlyRate * 1.50;
    $value += ($mins['util_noturno']    / 60) * $hourlyRate * 1.70; // 1.50 + 0.20
    $value += ($mins['domingo']         / 60) * $hourlyRate * 2.00;
    $value += ($mins['domingo_noturno'] / 60) * $hourlyRate * 2.20; // 2.00 + 0.20
    $value += ($mins['feriado']         / 60) * $hourlyRate * 2.00;
    $value += ($mins['feriado_noturno'] / 60) * $hourlyRate * 2.20;

    $value += $adicionaAttrativo;

    return [
        'hourlyRate'   => $hourlyRate,
        'totalValue'   => $value,
        'minutes'      => $mins,
        'totalMinutes' => array_sum($mins),
    ];
}

/**
 * Calcula o valor CLT de um único lançamento, iterando hora a hora.
 * Multiplicadores: diurno 1.50× | noturno 1.80× | dom/feriado 2.00× | dom/fer+noturno 2.20×
 * Base: salário ÷ 220h (CLT 44h/semana).
 */
function calcValorLancamento(array $lanc, float $salarioBruto): float {
    if ($salarioBruto <= 0) return 0.0;
    $ini = substr((string)($lanc['hora_inicio'] ?? ''), 0, 5);
    $fim = substr((string)($lanc['hora_fim']    ?? ''), 0, 5);
    if (strlen($ini) < 5 || strlen($fim) < 5) return 0.0;

    $hourlyBase = $salarioBruto / 220.0;
    $value      = 0.0;
    $isFeriado  = filter_var($lanc['feriado'] ?? false, FILTER_VALIDATE_BOOLEAN);

    [$cur, $end] = lancamentoDatetimes($lanc['data_acionamento'], $ini, $fim);

    while ($cur < $end) {
        $slot = new DateTime($cur->format('Y-m-d H:00:00'));
        $slot->modify('+1 hour');
        $seg = $slot > $end ? $end : $slot;

        $segMins   = ($seg->getTimestamp() - $cur->getTimestamp()) / 60.0;
        $h         = (int)$cur->format('G');
        $dow       = (int)$cur->format('w'); // 0=dom
        $isNight   = ($h >= 22 || $h < 5);
        $isDomingo = ($dow === 0);

        if      ($isFeriado && $isNight) $mult = 2.20;
        elseif  ($isFeriado)             $mult = 2.00;
        elseif  ($isDomingo && $isNight) $mult = 2.20;
        elseif  ($isDomingo)             $mult = 2.00;
        elseif  ($isNight)               $mult = 1.80;
        else                             $mult = 1.50;

        $value += ($segMins / 60.0) * $hourlyBase * $mult;
        $cur = $seg;
    }

    return round($value, 2);
}

// ── Helpers de geração ────────────────────────────────────────────────────────

function generateTempPassword(int $len = 10): string {
    $c = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < $len; $i++) $p .= $c[random_int(0, strlen($c) - 1)];
    return $p;
}

function generateToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

// ── I/O JSON ─────────────────────────────────────────────────────────────────

function jsonBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function jsonOut(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
