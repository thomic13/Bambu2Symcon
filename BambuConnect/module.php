<?php

declare(strict_types=1);

class BambuConnect extends IPSModuleStrict
{
    private const MQTT_CLIENT_MODULE_ID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';

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
        ['ident' => 'PrintErrorCode', 'name' => 'Druck Fehlercode', 'type' => 1, 'profile' => ''],
        ['ident' => 'PrintErrorText', 'name' => 'Druck Fehlertext', 'type' => 3, 'profile' => '']
    ];

    private const ADVANCED_VARIABLES = [
        ['ident' => 'AmsTemperature', 'name' => 'AMS Temperatur', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'AmsHumidity', 'name' => 'AMS Feuchte', 'type' => 1, 'profile' => '~Humidity']
    ];

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent(self::MQTT_CLIENT_MODULE_ID);

        $this->RegisterPropertyString('PrinterSerial', '');
        $this->RegisterPropertyString('MqttTopic', 'device/{SERIAL}/report');
        $this->RegisterPropertyBoolean('CreateStatusVariables', true);
        $this->RegisterPropertyBoolean('CreateAdvancedVariables', false);
        $this->RegisterPropertyBoolean('ShowAdvancedMetrics', true);
        $this->RegisterPropertyString('TileTitle', 'Bambu Drucker');
        $this->RegisterPropertyString('AccentColor', 'symcon');
        $this->RegisterPropertyString('ProgressRingSize', 'medium');
        $this->RegisterPropertyBoolean('ShowAmsFilaments', true);
        $this->RegisterPropertyBoolean('ShowAmsRemaining', true);
        $this->RegisterAttributeString('LastState', json_encode($this->emptyState(), JSON_UNESCAPED_UNICODE));
        $this->RegisterAttributeString('LastPayload', '');
        $this->RegisterAttributeString('PayloadBuffer', '');
        $this->RegisterAttributeInteger('LastPayloadTimestamp', 0);
        $this->RegisterTimer('KeepAliveTimer', 0, 'BAMBU_MqttPing($_IPS["TARGET"]);');
        $this->RegisterTimer('ConnectionWatchdogTimer', 0, 'BAMBU_CheckConnection($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ConnectParent(self::MQTT_CLIENT_MODULE_ID);

        $this->SetVisualizationType(1);
        $this->SetReceiveDataFilter($this->buildReceiveDataFilter());
        $this->MaintainStatusVariables();
        $this->syncStatusVariables($this->getState());
        $this->SetTimerInterval('KeepAliveTimer', 0);
        $this->SetTimerInterval('ConnectionWatchdogTimer', 0);
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

    private function processPayload(string $Payload): bool
    {
        $chunk = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $Payload);
        if ($chunk === null || $chunk === '') {
            return false;
        }

        $buffer = $this->ReadAttributeString('PayloadBuffer') . $chunk;
        $json = $this->extractJson($buffer);
        if ($json === '') {
            $this->WriteAttributeString('PayloadBuffer', '');
            $this->SendDebug('Payload', 'Kein JSON-Anfang gefunden', 0);
            return false;
        }

        if (strlen($json) > 300000) {
            $this->WriteAttributeString('PayloadBuffer', '');
            $this->SendDebug('Payload', 'Buffer verworfen, zu gross', 0);
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->WriteAttributeString('PayloadBuffer', $json);
            $this->SendDebug('Payload', 'JSON noch nicht vollstaendig: ' . json_last_error_msg(), 0);
            return false;
        }

        $state = $this->parseState($data);
        $this->WriteAttributeString('PayloadBuffer', '');
        $this->WriteAttributeString('LastPayload', $json);
        $this->WriteAttributeString('LastState', json_encode($state, JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeInteger('LastPayloadTimestamp', time());

        $this->MaintainStatusVariables();
        $this->syncStatusVariables($state);
        $this->UpdateVisualizationValue(json_encode($this->buildPayload(), JSON_UNESCAPED_UNICODE));
        $this->SendDebug(
            'Payload',
            sprintf(
                'Verarbeitet: %s, %d%%, %s',
                $state['statusLabel'],
                (int) $state['progress'],
                $state['printName'] !== '' ? $state['printName'] : 'Kein Druckauftrag'
            ),
            0
        );

        return true;
    }

    public function CheckConnection(): void
    {
        // Legacy timer target kept as no-op for existing beta instances.
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug('ReceiveData', 'Ungueltiges Datenpaket', 0);
            return '';
        }

        if (array_key_exists('Payload', $data)) {
            $topic = (string)($data['Topic'] ?? '');
            $payload = (string)$data['Payload'];
            if (!$this->topicMatches($topic)) {
                return '';
            }

            if (function_exists('IPS_GetKernelDate') && IPS_GetKernelDate() > 1670886000) {
                $payload = utf8_decode($payload);
            }

            $this->SendDebug('MQTT Topic', $topic, 0);
            $this->processPayload($payload);
            return '';
        }

        return '';
    }

    public function ConnectMqtt(): bool
    {
        $this->SendDebug('MQTT', 'MQTT wird ueber die verbundene MQTT-Client-Instanz verwaltet.', 0);
        return false;
    }

    public function SubscribeMqtt(): bool
    {
        $this->SendDebug('MQTT', 'Subscriptions werden in der verbundenen MQTT-Client-Instanz verwaltet.', 0);
        return false;
    }

    public function MqttPing(): void
    {
        // Legacy timer target kept as no-op for existing beta instances.
    }

    public function MaintainStatusVariables(): void
    {
        $statusEnabled = $this->ReadPropertyBoolean('CreateStatusVariables');
        foreach (self::STATUS_VARIABLES as $index => $variable) {
            $this->MaintainVariable(
                $variable['ident'],
                $variable['name'],
                $variable['type'],
                $variable['profile'],
                $index + 1,
                $statusEnabled
            );
        }

        $advancedEnabled = $this->ReadPropertyBoolean('CreateAdvancedVariables');
        foreach (self::ADVANCED_VARIABLES as $index => $variable) {
            $this->MaintainVariable(
                $variable['ident'],
                $variable['name'],
                $variable['type'],
                $variable['profile'],
                count(self::STATUS_VARIABLES) + $index + 1,
                $advancedEnabled
            );
        }

        $this->MaintainVariable('AmsHumidityLevel', 'AMS Feuchtestufe', 1, '', 0, false);
    }

    private function buildPayload(): array
    {
        $state = $this->getState();

        return [
            'title' => $this->ReadPropertyString('TileTitle'),
            'accent' => $this->ReadPropertyString('AccentColor'),
            'ringSize' => $this->ReadPropertyString('ProgressRingSize'),
            'showAdvancedMetrics' => $this->ReadPropertyBoolean('ShowAdvancedMetrics'),
            'showAmsFilaments' => $this->ReadPropertyBoolean('ShowAmsFilaments'),
            'showAmsRemaining' => $this->ReadPropertyBoolean('ShowAmsRemaining'),
            'printer' => $state,
            'metrics' => $this->buildMetrics($state),
            'details' => $this->buildDetails($state)
        ];
    }

    private function topicMatches(string $topic): bool
    {
        $filter = $this->resolveMqttTopic();
        if ($filter === '' || $topic === '') {
            return true;
        }

        $pattern = preg_quote($filter, '/');
        $pattern = str_replace(['\+', '\#'], ['[^/]+', '.*'], $pattern);
        return preg_match('/^' . $pattern . '$/', $topic) === 1;
    }

    private function buildReceiveDataFilter(): string
    {
        $topic = $this->resolveMqttTopic();
        if ($topic === '') {
            return '';
        }

        $pattern = preg_quote($topic, '/');
        $pattern = str_replace(['\+', '\#'], ['[^/]+', '.*'], $pattern);
        return '.*"Topic":"' . $pattern . '".*';
    }

    private function resolveMqttTopic(): string
    {
        $topic = trim($this->ReadPropertyString('MqttTopic'));
        $serial = trim($this->ReadPropertyString('PrinterSerial'));

        if ($topic === '') {
            $topic = 'device/{SERIAL}/report';
        }

        if ($serial !== '') {
            $topic = str_replace(['{SERIAL}', '%SERIAL%'], $serial, $topic);
        }

        return $topic;
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

    private function buildDetails(array $state): array
    {
        return [
            'amsFilaments' => $state['amsFilaments']
        ];
    }

    private function parseState(array $data): array
    {
        $print = is_array($data['print'] ?? null) ? $data['print'] : $data;
        $state = array_replace($this->emptyState(), $this->getState());
        $state['updatedAt'] = date('c');

        $status = $this->stringValue($print, ['gcode_state', 'print_status']);
        if ($status !== '') {
            $state['status'] = $status;
            $state['statusLabel'] = $this->statusLabel($status);
        }

        $printName = $this->stringValue($print, ['subtask_name', 'project_name', 'gcode_file']);
        if ($printName !== '') {
            $state['printName'] = $printName;
        } elseif ($status !== '' && in_array(strtoupper($status), ['IDLE', 'FINISH', 'FINISHED'], true)) {
            $state['printName'] = '';
        }

        $progress = $this->numericValueOrNull($print, ['mc_percent', 'percent']);
        if ($progress !== null) {
            $state['progress'] = max(0, min(100, (int)$progress));
        }

        $remainingMinutes = $this->numericValueOrNull($print, ['mc_remaining_time', 'remain_time']);
        if ($remainingMinutes !== null) {
            $state['remainingMinutes'] = (int)$remainingMinutes;
            $state['remainingText'] = $this->formatRemaining((int)$remainingMinutes);
        }

        $layer = $this->firstNumericOrNull([
            $print['layer_num'] ?? null,
            $print['3D']['layer_num'] ?? null
        ]);
        if ($layer !== null) {
            $state['layer'] = (int)$layer;
        }

        $totalLayers = $this->firstNumericOrNull([
            $print['total_layer_num'] ?? null,
            $print['3D']['total_layer_num'] ?? null
        ]);
        if ($totalLayers !== null) {
            $state['totalLayers'] = (int)$totalLayers;
        }

        foreach ([
            'nozzleTemperature' => ['nozzle_temper'],
            'nozzleTargetTemperature' => ['nozzle_target_temper'],
            'bedTemperature' => ['bed_temper'],
            'bedTargetTemperature' => ['bed_target_temper']
        ] as $stateKey => $keys) {
            $value = $this->numericValueOrNull($print, $keys);
            if ($value !== null) {
                $state[$stateKey] = (float)$value;
            }
        }

        $chamberTemperature = $this->firstNumericOrNull([
            $print['chamber_temper'] ?? null,
            $print['device']['ctc']['info']['temp'] ?? null
        ]);
        if ($chamberTemperature !== null) {
            $state['chamberTemperature'] = (float)$chamberTemperature;
        }

        $wifiSignal = $this->stringValue($print, ['wifi_signal']);
        if ($wifiSignal !== '') {
            $state['wifiSignal'] = $wifiSignal;
        }

        $printError = $this->numericValueOrNull($print, ['print_error']);
        if ($printError !== null) {
            $state['errorCode'] = (int)$printError;
            $state['errorText'] = (int)$printError === 0 ? 'Kein Fehler' : 'Fehler ' . (int)$printError;
        }

        $amsTemperature = $this->firstNumericOrNull([
            $print['ams']['ams'][0]['temp'] ?? null
        ]);
        if ($amsTemperature !== null) {
            $state['amsTemperature'] = (float)$amsTemperature;
        }

        $amsHumidity = $this->firstNumericOrNull([
            $print['ams']['ams'][0]['humidity_raw'] ?? null
        ]);
        if ($amsHumidity !== null) {
            $state['amsHumidity'] = (float)$amsHumidity;
        }

        $amsFilaments = $this->parseAmsFilaments($print);
        if ($amsFilaments !== []) {
            $state['amsFilaments'] = $amsFilaments;
        }

        return $state;
    }

    private function syncStatusVariables(array $state): void
    {
        $statusEnabled = $this->ReadPropertyBoolean('CreateStatusVariables');
        $advancedEnabled = $this->ReadPropertyBoolean('CreateAdvancedVariables');

        if (!$statusEnabled && !$advancedEnabled) {
            return;
        }

        $values = [];

        if ($statusEnabled) {
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
            'PrintErrorCode' => $state['errorCode'],
            'PrintErrorText' => $state['errorText']
            ];
        }

        if ($advancedEnabled) {
            $values += [
                'AmsTemperature' => $state['amsTemperature'],
                'AmsHumidity' => (int) $state['amsHumidity']
            ];
        }

        foreach ($values as $ident => $value) {
            $objectID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($objectID !== false) {
                $this->SetValue($ident, $value);
            }
        }

        $this->syncAmsFilamentVariables($state, $advancedEnabled);
    }

    private function syncAmsFilamentVariables(array $state, bool $enabled): void
    {
        $filaments = is_array($state['amsFilaments'] ?? null) ? $state['amsFilaments'] : [];
        if (!$enabled && $filaments === []) {
            $this->disableKnownAmsFilamentVariables();
            return;
        }

        $basePosition = count(self::STATUS_VARIABLES) + count(self::ADVANCED_VARIABLES) + 1;

        foreach ($filaments as $index => $filament) {
            if (!is_array($filament)) {
                continue;
            }

            $slot = $this->sanitizeIdentPart((string)($filament['slot'] ?? (string)($index + 1)));
            $position = $basePosition + ($index * 3);

            $materialIdent = 'AmsFilament' . $slot . 'Material';
            $colorIdent = 'AmsFilament' . $slot . 'Color';
            $remainingIdent = 'AmsFilament' . $slot . 'Remaining';

            $this->MaintainVariable($materialIdent, 'AMS Slot ' . ($filament['slot'] ?? $index + 1) . ' Material', 3, '', $position, $enabled);
            $this->MaintainVariable($colorIdent, 'AMS Slot ' . ($filament['slot'] ?? $index + 1) . ' Farbe', 3, '', $position + 1, $enabled);
            $this->MaintainVariable($remainingIdent, 'AMS Slot ' . ($filament['slot'] ?? $index + 1) . ' Rest', 1, '~Intensity.100', $position + 2, $enabled);

            if (!$enabled) {
                continue;
            }

            $this->SetValue($materialIdent, (string)($filament['name'] ?? 'Unbekannt'));
            $this->SetValue($colorIdent, $this->colorToSymconRgbValue((string)($filament['color'] ?? '')));
            $this->SetValue($remainingIdent, (int)round((float)($filament['remaining'] ?? 0)));
        }
    }

    private function disableKnownAmsFilamentVariables(): void
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($children as $childID) {
            $object = IPS_GetObject($childID);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if (preg_match('/^AmsFilament.+(Material|Color|Remaining)$/', $ident) !== 1) {
                continue;
            }

            @IPS_DeleteVariable($childID);
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
            'amsHumidity' => 0.0,
            'amsFilaments' => []
        ];
    }

    private function parseAmsFilaments(array $print): array
    {
        $units = $print['ams']['ams'] ?? [];
        if (!is_array($units)) {
            return [];
        }

        $filaments = [];
        foreach ($units as $unitIndex => $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $trays = $unit['tray'] ?? $unit['trays'] ?? [];
            if (!is_array($trays)) {
                continue;
            }

            foreach ($trays as $trayIndex => $tray) {
                if (!is_array($tray)) {
                    continue;
                }

                $name = $this->firstString([
                    $tray['tray_type'] ?? null,
                    $tray['filament_type'] ?? null,
                    $tray['tray_sub_brands'] ?? null,
                    $tray['name'] ?? null
                ]);

                $color = $this->normalizeColor($this->firstString([
                    $tray['tray_color'] ?? null,
                    $tray['color'] ?? null
                ]));

                $remaining = $this->firstNumericOrNull([
                    $tray['remain'] ?? null,
                    $tray['remaining'] ?? null,
                    $tray['tray_remaining'] ?? null,
                    $tray['tray_percent'] ?? null,
                    $tray['remaining_percent'] ?? null,
                    $tray['percent'] ?? null
                ]);

                if ($name === '' && $remaining === null && $color === '') {
                    continue;
                }

                $slot = $this->firstString([
                    $tray['id'] ?? null,
                    $tray['tray_id'] ?? null,
                    $tray['slot'] ?? null
                ]);

                if ($slot === '') {
                    $slot = ((int) $unitIndex + 1) . '.' . ((int) $trayIndex + 1);
                }

                $filaments[] = [
                    'slot' => $slot,
                    'name' => $name !== '' ? $name : 'Unbekannt',
                    'color' => $color !== '' ? $color : '#d8e1e8',
                    'remaining' => $remaining
                ];
            }
        }

        return $filaments;
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

    private function numericValueOrNull(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float)$data[$key];
            }
        }

        return null;
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

    private function firstNumericOrNull(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function sanitizeIdentPart(string $value): string
    {
        $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        if ($ident === null || $ident === '') {
            return 'Unknown';
        }

        return $ident;
    }

    private function normalizeColor(string $value): string
    {
        $color = ltrim(trim($value), '#');
        if ($color === '') {
            return '';
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $color) === 1) {
            return '#' . $color;
        }

        if (preg_match('/^[0-9a-fA-F]{8}$/', $color) === 1) {
            return '#' . substr($color, 0, 6);
        }

        return '';
    }

    private function colorToSymconRgbValue(string $value): string
    {
        $color = $this->normalizeColor($value);
        if ($color === '') {
            return '';
        }

        return json_encode([
            'r' => hexdec(substr($color, 1, 2)),
            'g' => hexdec(substr($color, 3, 2)),
            'b' => hexdec(substr($color, 5, 2))
        ]);
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

    private function formatNumeric(float $value): string
    {
        return $value > 0 ? sprintf('%.0f', $value) : '--';
    }

    private function formatLayer(array $state): string
    {
        if ((int) $state['totalLayers'] > 0) {
            return sprintf('%d / %d', (int) $state['layer'], (int) $state['totalLayers']);
        }

        return (int) $state['layer'] > 0 ? (string) $state['layer'] : '--';
    }
}
