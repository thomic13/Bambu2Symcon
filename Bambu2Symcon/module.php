<?php

declare(strict_types=1);

class Bambu2Symcon extends IPSModuleStrict
{
    private const STATUS_VARIABLES = [
        ['ident' => 'PrinterStatus', 'name' => 'Status', 'type' => 3, 'profile' => ''],
        ['ident' => 'PrintName', 'name' => 'Druckname', 'type' => 3, 'profile' => ''],
        ['ident' => 'Progress', 'name' => 'Fortschritt', 'type' => 1, 'profile' => '~Intensity.100'],
        ['ident' => 'RemainingMinutes', 'name' => 'Restzeit Minuten', 'type' => 1, 'profile' => ''],
        ['ident' => 'RemainingText', 'name' => 'Restzeit Text', 'type' => 3, 'profile' => ''],
        ['ident' => 'NozzleTemperature', 'name' => 'Nozzle Ist', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'NozzleTargetTemperature', 'name' => 'Nozzle Soll', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'BedTemperature', 'name' => 'Bett Ist', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'BedTargetTemperature', 'name' => 'Bett Soll', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'ChamberTemperature', 'name' => 'Bauraum Temperatur', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'Layer', 'name' => 'Layer', 'type' => 1, 'profile' => ''],
        ['ident' => 'TotalLayers', 'name' => 'Layer Gesamt', 'type' => 1, 'profile' => ''],
        ['ident' => 'WifiSignal', 'name' => 'WLAN Signal', 'type' => 3, 'profile' => ''],
        ['ident' => 'PrintErrorText', 'name' => 'Druck Fehlertext', 'type' => 3, 'profile' => ''],
        ['ident' => 'AmsTemperature', 'name' => 'AMS Temperatur', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'AmsHumidity', 'name' => 'AMS Feuchte', 'type' => 1, 'profile' => '']
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('CreateStatusVariables', true);
        $this->RegisterPropertyBoolean('ShowAdvancedMetrics', true);
        $this->RegisterPropertyString('TileTitle', 'Bambu H2S');
        $this->RegisterPropertyString('AccentColor', 'symcon');
        $this->RegisterAttributeString('LastState', json_encode($this->emptyState(), JSON_UNESCAPED_UNICODE));
        $this->RegisterAttributeString('LastPayload', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->MaintainStatusVariables();
        $this->syncStatusVariables($this->getState());
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '';
        }

        return str_replace(
            '%%INITIAL_DATA%%',
            json_encode($this->buildPayload(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            $html
        );
    }

    public function ProcessPayload(string $Payload): bool
    {
        $json = $this->extractJson($Payload);
        if ($json === '') {
            $this->SendDebug('Payload', 'Kein JSON-Anfang gefunden', 0);
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->SendDebug('Payload', 'JSON Fehler: ' . json_last_error_msg(), 0);
            return false;
        }

        $state = $this->parseState($data);
        $this->WriteAttributeString('LastPayload', $json);
        $this->WriteAttributeString('LastState', json_encode($state, JSON_UNESCAPED_UNICODE));

        $this->MaintainStatusVariables();
        $this->syncStatusVariables($state);
        $this->UpdateVisualizationValue(json_encode($this->buildPayload(), JSON_UNESCAPED_UNICODE));

        return true;
    }

    public function MaintainStatusVariables(): void
    {
        $enabled = $this->ReadPropertyBoolean('CreateStatusVariables');
        foreach (self::STATUS_VARIABLES as $index => $variable) {
            $this->MaintainVariable(
                $variable['ident'],
                $variable['name'],
                $variable['type'],
                $variable['profile'],
                $index + 1,
                $enabled
            );
        }
    }

    private function buildPayload(): array
    {
        $state = $this->getState();

        return [
            'title' => $this->ReadPropertyString('TileTitle'),
            'accent' => $this->ReadPropertyString('AccentColor'),
            'showAdvancedMetrics' => $this->ReadPropertyBoolean('ShowAdvancedMetrics'),
            'printer' => $state,
            'metrics' => $this->buildMetrics($state)
        ];
    }

    private function buildMetrics(array $state): array
    {
        return [
            'primary' => [
                $this->metric('Nozzle', $this->formatTemperature($state['nozzleTemperature']), $this->formatTemperature($state['nozzleTargetTemperature']), 'temperature'),
                $this->metric('Bett', $this->formatTemperature($state['bedTemperature']), $this->formatTemperature($state['bedTargetTemperature']), 'bed'),
                $this->metric('Restzeit', $state['remainingText'], '', 'time'),
                $this->metric('Layer', $this->formatLayer($state), '', 'layer')
            ],
            'secondary' => [
                $this->metric('Bauraum', $this->formatTemperature($state['chamberTemperature']), '', 'chamber'),
                $this->metric('WLAN', $state['wifiSignal'], '', 'wifi'),
                $this->metric('AMS Temp', $this->formatTemperature($state['amsTemperature']), '', 'ams'),
                $this->metric('AMS Feuchte', $this->formatPercent($state['amsHumidity']), '', 'humidity'),
                $this->metric('Fehler', $state['errorText'], '', 'error')
            ]
        ];
    }

    private function metric(string $label, string $value, string $target, string $kind): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'target' => $target,
            'kind' => $kind
        ];
    }

    private function parseState(array $data): array
    {
        $print = is_array($data['print'] ?? null) ? $data['print'] : $data;
        $remainingMinutes = $this->intValue($print, ['mc_remaining_time', 'remain_time']);
        $printError = $this->intValue($print, ['print_error']);

        return [
            'updatedAt' => date('c'),
            'status' => $this->stringValue($print, ['gcode_state', 'print_status']),
            'statusLabel' => $this->statusLabel($this->stringValue($print, ['gcode_state', 'print_status'])),
            'printName' => $this->stringValue($print, ['subtask_name', 'project_name', 'gcode_file']),
            'progress' => max(0, min(100, $this->intValue($print, ['mc_percent', 'percent']))),
            'remainingMinutes' => $remainingMinutes,
            'remainingText' => $this->formatRemaining($remainingMinutes),
            'layer' => $this->intValue($print, ['layer_num']),
            'totalLayers' => $this->intValue($print, ['total_layer_num']),
            'nozzleTemperature' => $this->floatValue($print, ['nozzle_temper']),
            'nozzleTargetTemperature' => $this->floatValue($print, ['nozzle_target_temper']),
            'bedTemperature' => $this->floatValue($print, ['bed_temper']),
            'bedTargetTemperature' => $this->floatValue($print, ['bed_target_temper']),
            'chamberTemperature' => $this->firstNumeric([
                $print['chamber_temper'] ?? null,
                $print['device']['ctc']['info']['temp'] ?? null
            ]),
            'wifiSignal' => $this->stringValue($print, ['wifi_signal']),
            'errorCode' => $printError,
            'errorText' => $printError === 0 ? 'Kein Fehler' : 'Fehler ' . $printError,
            'amsTemperature' => $this->firstNumeric([
                $print['ams']['ams'][0]['temp'] ?? null
            ]),
            'amsHumidity' => $this->firstNumeric([
                $print['ams']['ams'][0]['humidity'] ?? null
            ])
        ];
    }

    private function syncStatusVariables(array $state): void
    {
        if (!$this->ReadPropertyBoolean('CreateStatusVariables')) {
            return;
        }

        $values = [
            'PrinterStatus' => $state['statusLabel'],
            'PrintName' => $state['printName'],
            'Progress' => $state['progress'],
            'RemainingMinutes' => $state['remainingMinutes'],
            'RemainingText' => $state['remainingText'],
            'NozzleTemperature' => $state['nozzleTemperature'],
            'NozzleTargetTemperature' => $state['nozzleTargetTemperature'],
            'BedTemperature' => $state['bedTemperature'],
            'BedTargetTemperature' => $state['bedTargetTemperature'],
            'ChamberTemperature' => $state['chamberTemperature'],
            'Layer' => $state['layer'],
            'TotalLayers' => $state['totalLayers'],
            'WifiSignal' => $state['wifiSignal'],
            'PrintErrorText' => $state['errorText'],
            'AmsTemperature' => $state['amsTemperature'],
            'AmsHumidity' => (int) $state['amsHumidity']
        ];

        foreach ($values as $ident => $value) {
            $objectID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($objectID !== false) {
                $this->SetValue($ident, $value);
            }
        }
    }

    private function getState(): array
    {
        $state = json_decode($this->ReadAttributeString('LastState'), true);
        return is_array($state) ? array_replace($this->emptyState(), $state) : $this->emptyState();
    }

    private function emptyState(): array
    {
        return [
            'updatedAt' => '',
            'status' => '',
            'statusLabel' => 'Unbekannt',
            'printName' => 'Kein Druckauftrag',
            'progress' => 0,
            'remainingMinutes' => 0,
            'remainingText' => '--:-- h',
            'layer' => 0,
            'totalLayers' => 0,
            'nozzleTemperature' => 0.0,
            'nozzleTargetTemperature' => 0.0,
            'bedTemperature' => 0.0,
            'bedTargetTemperature' => 0.0,
            'chamberTemperature' => 0.0,
            'wifiSignal' => '',
            'errorCode' => 0,
            'errorText' => 'Kein Fehler',
            'amsTemperature' => 0.0,
            'amsHumidity' => 0.0
        ];
    }

    private function extractJson(string $payload): string
    {
        $position = strpos($payload, '{');
        return $position === false ? '' : substr($payload, $position);
    }

    private function stringValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    private function intValue(array $data, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return 0;
    }

    private function floatValue(array $data, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return 0.0;
    }

    private function firstNumeric(array $values): float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    private function statusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'RUNNING', 'PREPARE', 'SLICING', 'PAUSE_RESUME' => 'Druckt',
            'PAUSE', 'PAUSED' => 'Pausiert',
            'FINISH', 'FINISHED' => 'Fertig',
            'FAILED', 'ERROR' => 'Fehler',
            'IDLE' => 'Bereit',
            default => $status !== '' ? $status : 'Unbekannt'
        };
    }

    private function formatRemaining(int $minutes): string
    {
        if ($minutes <= 0) {
            return '--:-- h';
        }

        return sprintf('%02d:%02d h', intdiv($minutes, 60), $minutes % 60);
    }

    private function formatTemperature(float $value): string
    {
        return $value > 0 ? sprintf('%.1f °C', $value) : '-- °C';
    }

    private function formatPercent(float $value): string
    {
        return $value > 0 ? sprintf('%.0f %%', $value) : '-- %';
    }

    private function formatLayer(array $state): string
    {
        if ((int) $state['totalLayers'] > 0) {
            return sprintf('%d / %d', (int) $state['layer'], (int) $state['totalLayers']);
        }

        return (int) $state['layer'] > 0 ? (string) $state['layer'] : '--';
    }
}
