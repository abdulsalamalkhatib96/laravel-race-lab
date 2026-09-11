<?php

namespace RaceLab\LaravelRaceLab\Results;

final class TimelineReporter
{
    public function render(RaceResult $result): string
    {
        $events = $result->timeline();
        $base = $events[0]['timestamp'] ?? microtime(true);
        $lines = [];
        $lines[] = 'Race: '.$result->plan->name;
        $lines[] = 'Run: '.$result->plan->runId;
        $lines[] = 'Determinism: '.$result->plan->determinism->value;
        $lines[] = str_repeat('-', 78);

        foreach ($events as $event) {
            $ms = (($event['timestamp'] ?? $base) - $base) * 1000;
            $worker = $event['worker'] ?? null;
            $prefix = sprintf('%8.3fms  %-12s  %-28s', $ms, $worker ? '['.$worker.']' : '[coordinator]', (string) ($event['type'] ?? 'event'));
            $data = $event['data'] ?? [];
            $summary = $this->summarizeData($data);
            $lines[] = rtrim($prefix.($summary !== '' ? ' '.$summary : ''));
        }

        $lines[] = str_repeat('-', 78);
        foreach ($result->workers() as $worker) {
            $lines[] = sprintf(
                '%-20s %-12s %8.3fms%s',
                $worker->workerId,
                $worker->status->value,
                $worker->durationMilliseconds(),
                $worker->exception ? '  '.$worker->exception->class.': '.$worker->exception->message : '',
            );
        }

        return implode(PHP_EOL, $lines);
    }

    private function summarizeData(array $data): string
    {
        $preferred = [];
        foreach (['barrier', 'phase', 'connection', 'operation', 'success', 'duration_ms', 'failure_type', 'exception', 'message'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_scalar($data[$key]) || $data[$key] === null ? (string) $data[$key] : json_encode($data[$key]);
                $preferred[] = $key.'='.$value;
            }
        }

        if (isset($data['sql'])) {
            $sql = preg_replace('/\s+/', ' ', trim((string) $data['sql']));
            $preferred[] = 'sql='.substr($sql, 0, 180).(strlen($sql) > 180 ? '…' : '');
        }

        return implode(' ', $preferred);
    }
}
