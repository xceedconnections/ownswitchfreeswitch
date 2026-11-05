<?php

declare(strict_types=1);

namespace Ownswitch\Services\System;

/**
 * Live host resource snapshot for the admin dashboard (CPU, RAM, disk, NIC throughput).
 * Prefers /proc; falls back to shell tools when open_basedir blocks /proc.
 */
final class HostMetricsService
{
    /**
     * @return array{
     *   hostname:string,uptime_s:int,cpu_cores:int,cpu_pct:float,load1:float,load5:float,load15:float,
     *   ram_total_mb:int,ram_used_mb:int,ram_pct:float,
     *   disk_total_gb:float,disk_used_gb:float,disk_free_gb:float,disk_pct:float,
     *   net_rx_mbps:float,net_tx_mbps:float,net_iface:string,sampled_at:string
     * }
     */
    public function snapshot(): array
    {
        $cores = max(1, (int) (trim((string) (@shell_exec('nproc 2>/dev/null') ?: '')) ?: 1));
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $cpuPct = $this->cpuPercent($cores);
        [$ramTotalMb, $ramUsedMb] = $this->memMb();
        $ramPct = $ramTotalMb > 0 ? round(100 * $ramUsedMb / $ramTotalMb, 1) : 0.0;

        [$diskTotal, $diskUsed, $diskFree, $diskPct] = $this->diskGb();
        [$iface, $rxMbps, $txMbps] = $this->netMbps();

        return [
            'hostname' => gethostname() ?: 'server',
            'uptime_s' => $this->uptimeSeconds(),
            'cpu_cores' => $cores,
            'cpu_pct' => $cpuPct,
            'load1' => round((float) $load[0], 2),
            'load5' => round((float) $load[1], 2),
            'load15' => round((float) $load[2], 2),
            'ram_total_mb' => $ramTotalMb,
            'ram_used_mb' => $ramUsedMb,
            'ram_pct' => $ramPct,
            'disk_total_gb' => $diskTotal,
            'disk_used_gb' => $diskUsed,
            'disk_free_gb' => $diskFree,
            'disk_pct' => $diskPct,
            'net_rx_mbps' => $rxMbps,
            'net_tx_mbps' => $txMbps,
            'net_iface' => $iface,
            'sampled_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
    }

    private function cpuPercent(int $cores): float
    {
        $a = $this->cpuTimes();
        usleep(400000);
        $b = $this->cpuTimes();
        if ($a === null || $b === null) {
            $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0]) : [0];
            return round(min(100, 100 * ((float) $load[0] / max(1, $cores))), 1);
        }
        $idle = $b['idle'] - $a['idle'];
        $total = $b['total'] - $a['total'];
        if ($total <= 0) {
            return 0.0;
        }
        return round(max(0, min(100, 100 * (1 - ($idle / $total)))), 1);
    }

    /** @return array{idle:int,total:int}|null */
    private function cpuTimes(): ?array
    {
        $line = $this->readFirstLine('/proc/stat');
        if ($line === null || !str_starts_with($line, 'cpu ')) {
            return null;
        }
        $p = preg_split('/\s+/', trim($line)) ?: [];
        $nums = array_map('intval', array_slice($p, 1));
        if (count($nums) < 4) {
            return null;
        }
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
        $total = array_sum($nums);
        return ['idle' => $idle, 'total' => $total];
    }

    /** @return array{0:int,1:int} totalMb, usedMb */
    private function memMb(): array
    {
        $totalKb = $availKb = 0;
        $lines = $this->readLines('/proc/meminfo');
        foreach ($lines as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
                $totalKb = (int) $m[1];
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) {
                $availKb = (int) $m[1];
            }
        }
        if ($totalKb > 0) {
            $usedKb = max(0, $totalKb - $availKb);
            return [(int) round($totalKb / 1024), (int) round($usedKb / 1024)];
        }
        // free -m fallback (open_basedir may block /proc)
        $out = (string) (@shell_exec('free -m 2>/dev/null') ?: '');
        if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $out, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, 0];
    }

    /** @return array{0:float,1:float,2:float,3:float} total, used, free, pct */
    private function diskGb(): array
    {
        $diskTotal = @disk_total_space('/') ?: 0.0;
        $diskFree = @disk_free_space('/') ?: 0.0;
        if ($diskTotal <= 0) {
            $out = (string) (@shell_exec("df -B1 / 2>/dev/null | awk 'NR==2{print $2\" \"$3\" \"$4}'") ?: '');
            if (preg_match('/^(\d+)\s+(\d+)\s+(\d+)/', trim($out), $m)) {
                $diskTotal = (float) $m[1];
                $diskUsed = (float) $m[2];
                $diskFree = (float) $m[3];
                $pct = $diskTotal > 0 ? round(100 * $diskUsed / $diskTotal, 1) : 0.0;
                return [
                    round($diskTotal / 1073741824, 1),
                    round($diskUsed / 1073741824, 1),
                    round($diskFree / 1073741824, 1),
                    $pct,
                ];
            }
            return [0.0, 0.0, 0.0, 0.0];
        }
        $diskUsed = max(0.0, $diskTotal - $diskFree);
        $pct = round(100 * $diskUsed / $diskTotal, 1);
        return [
            round($diskTotal / 1073741824, 1),
            round($diskUsed / 1073741824, 1),
            round($diskFree / 1073741824, 1),
            $pct,
        ];
    }

    private function uptimeSeconds(): int
    {
        $raw = $this->readFile('/proc/uptime');
        if ($raw !== null) {
            return (int) floatval(explode(' ', $raw)[0] ?? 0);
        }
        $out = trim((string) (@shell_exec('cat /proc/uptime 2>/dev/null') ?: ''));
        if ($out !== '') {
            return (int) floatval(explode(' ', $out)[0] ?? 0);
        }
        return 0;
    }

    /** @return array{0:string,1:float,2:float} iface, rx Mbps, tx Mbps */
    private function netMbps(): array
    {
        $a = $this->netCounters();
        usleep(500000);
        $b = $this->netCounters();
        if ($a === null || $b === null || $a['iface'] !== $b['iface']) {
            return ['—', 0.0, 0.0];
        }
        $dt = max(0.1, ($b['t'] - $a['t']));
        $rx = (($b['rx'] - $a['rx']) * 8) / $dt / 1_000_000;
        $tx = (($b['tx'] - $a['tx']) * 8) / $dt / 1_000_000;
        return [$a['iface'], round(max(0, $rx), 2), round(max(0, $tx), 2)];
    }

    /** @return array{iface:string,rx:int,tx:int,t:float}|null */
    private function netCounters(): ?array
    {
        $lines = $this->readLines('/proc/net/dev');
        if ($lines === []) {
            $raw = (string) (@shell_exec('cat /proc/net/dev 2>/dev/null') ?: '');
            $lines = $raw !== '' ? preg_split('/\r\n|\n|\r/', $raw) ?: [] : [];
        }
        $best = null;
        $bestSum = -1;
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$iface, $rest] = array_map('trim', explode(':', $line, 2));
            if ($iface === 'lo' || $iface === '') {
                continue;
            }
            $p = preg_split('/\s+/', $rest) ?: [];
            $rx = (int) ($p[0] ?? 0);
            $tx = (int) ($p[8] ?? 0);
            $sum = $rx + $tx;
            if ($sum > $bestSum) {
                $bestSum = $sum;
                $best = ['iface' => $iface, 'rx' => $rx, 'tx' => $tx, 't' => microtime(true)];
            }
        }
        return $best;
    }

    private function readFile(string $path): ?string
    {
        $raw = @file_get_contents($path);
        return $raw === false ? null : $raw;
    }

    private function readFirstLine(string $path): ?string
    {
        $lines = $this->readLines($path);
        return $lines[0] ?? null;
    }

    /** @return list<string> */
    private function readLines(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($lines) ? $lines : [];
    }
}
