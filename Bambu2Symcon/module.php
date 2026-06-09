<?php

declare(strict_types=1);

class Bambu2Symcon extends IPSModuleStrict
{
    private const CLIENT_SOCKET_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

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
        ['ident' => 'PrintErrorText', 'name' => 'Druck Fehlertext', 'type' => 3, 'profile' => ''],
        ['ident' => 'AmsTemperature', 'name' => 'AMS Temperatur', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'AmsHumidityLevel', 'name' => 'AMS Feuchtestufe', 'type' => 1, 'profile' => ''],
        ['ident' => 'AmsHumidity', 'name' => 'AMS Feuchte', 'type' => 1, 'profile' => '~Humidity']
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('PrinterSerial', '0938BC612702364');
        $this->RegisterPropertyString('MqttTopic', 'device/0938BC612702364/report');
        $this->RegisterPropertyString('ClientID', 'Bambu2Symcon');
        $this->RegisterPropertyString('UserName', 'bblp');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('MqttProtocolLevel', 4);
        $this->RegisterPropertyInteger('KeepAliveInterval', 60);
        $this->RegisterPropertyBoolean('AutoConnect', false);
        $this->RegisterPropertyBoolean('AutoSubscribe', true);
        $this->RegisterPropertyBoolean('CreateStatusVariables', true);
        $this->RegisterPropertyBoolean('ShowAdvancedMetrics', true);
        $this->RegisterPropertyString('TileTitle', 'Bambu H2S');
        $this->RegisterPropertyString('AccentColor', 'symcon');
        $this->RegisterAttributeString('LastState', json_encode($this->emptyState(), JSON_UNESCAPED_UNICODE));
        $this->RegisterAttributeString('LastPayload', '');
        $this->RegisterAttributeString('PayloadBuffer', '');
        $this->RegisterAttributeString('NetworkBuffer', '');
        $this->RegisterAttributeBoolean('MqttConnected', false);
        $this->RegisterAttributeInteger('PacketIdentifier', 1);
        $this->RegisterTimer('KeepAliveTimer', 0, 'BAMBU_MqttPing($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->MaintainStatusVariables();
        $this->syncStatusVariables($this->getState());
        $this->SetTimerInterval('KeepAliveTimer', 0);

        if ($this->ReadPropertyBoolean('AutoConnect')) {
            $this->ConnectMqtt();
        }
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

        $this->MaintainStatusVariables();
        $this->syncStatusVariables($state);
        $this->UpdateVisualizationValue(json_encode($this->buildPayload(), JSON_UNESCAPED_UNICODE));

        return true;
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug('ReceiveData', 'Ungueltiges Datenpaket', 0);
            return '';
        }

        $buffer = $this->decodeIpsBuffer((string)($data['Buffer'] ?? ''));
        if ($buffer === '') {
            return '';
        }

        $this->handleMqttBytes($buffer);
        return '';
    }

    public function ConnectMqtt(): bool
    {
        if (!$this->hasParent()) {
            $this->SendDebug('MQTT', 'Kein Client Socket verbunden', 0);
            return false;
        }

        $clientID = trim($this->ReadPropertyString('ClientID'));
        if ($clientID === '') {
            $clientID = 'Bambu2Symcon-' . $this->InstanceID;
        }

        $packet = $this->buildConnectPacket(
            $clientID,
            $this->ReadPropertyString('UserName'),
            $this->ReadPropertyString('Password'),
            max(15, $this->ReadPropertyInteger('KeepAliveInterval')),
            $this->ReadPropertyInteger('MqttProtocolLevel')
        );

        $this->WriteAttributeBoolean('MqttConnected', false);
        $this->WriteAttributeString('NetworkBuffer', '');
        if (!$this->sendToSocket($packet)) {
            return false;
        }

        $this->SendDebug('MQTT CONNECT HEX', bin2hex($packet), 0);
        $this->SendDebug('MQTT', 'CONNECT gesendet', 0);
        return true;
    }

    public function SubscribeMqtt(): bool
    {
        if (!$this->hasParent()) {
            $this->SendDebug('MQTT', 'Kein Client Socket verbunden', 0);
            return false;
        }

        if (!$this->ReadAttributeBoolean('MqttConnected')) {
            $this->SendDebug('MQTT', 'Noch nicht per MQTT verbunden, SUBSCRIBE uebersprungen', 0);
            return false;
        }

        $topic = trim($this->ReadPropertyString('MqttTopic'));
        if ($topic === '') {
            $this->SendDebug('MQTT', 'Kein Topic konfiguriert', 0);
            return false;
        }

        if (!$this->sendToSocket($this->buildSubscribePacket($topic))) {
            return false;
        }

        $this->SendDebug('MQTT', 'SUBSCRIBE gesendet: ' . $topic, 0);
        return true;
    }

    public function MqttPing(): void
    {
        if ($this->hasParent() && $this->ReadAttributeBoolean('MqttConnected')) {
            $this->sendToSocket(chr(0xC0) . chr(0x00));
        }
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

    private function hasParent(): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return ($instance['ConnectionID'] ?? 0) > 0;
    }

    private function sendToSocket(string $buffer): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $connectionID = (int)($instance['ConnectionID'] ?? 0);
        if ($connectionID <= 0) {
            $this->SendDebug('Socket', 'Kein Client Socket verbunden', 0);
            return false;
        }

        try {
            @CSCK_SendText($connectionID, utf8_encode($buffer));
        } catch (Throwable $exception) {
            $this->SendDebug('Socket', $exception->getMessage(), 0);
            return false;
        }

        return true;
    }

    private function handleMqttBytes(string $bytes): void
    {
        $buffer = $this->ReadAttributeString('NetworkBuffer') . $bytes;

        while ($buffer !== '') {
            $packet = $this->extractMqttPacket($buffer);
            if ($packet === null) {
                break;
            }

            $this->handleMqttPacket($packet);
        }

        $this->WriteAttributeString('NetworkBuffer', $buffer);
    }

    private function extractMqttPacket(string &$buffer): ?string
    {
        $length = strlen($buffer);
        if ($length < 2) {
            return null;
        }

        $multiplier = 1;
        $remainingLength = 0;
        $index = 1;

        do {
            if ($index >= $length) {
                return null;
            }

            $encodedByte = ord($buffer[$index]);
            $remainingLength += ($encodedByte & 127) * $multiplier;
            $multiplier *= 128;
            $index++;
        } while (($encodedByte & 128) !== 0 && $index <= 4);

        $totalLength = $index + $remainingLength;
        if ($length < $totalLength) {
            return null;
        }

        $packet = substr($buffer, 0, $totalLength);
        $buffer = substr($buffer, $totalLength);
        return $packet;
    }

    private function handleMqttPacket(string $packet): void
    {
        $firstByte = ord($packet[0]);
        $packetType = $firstByte >> 4;
        $flags = $firstByte & 0x0F;
        $offset = $this->fixedHeaderLength($packet);
        $body = substr($packet, $offset);

        match ($packetType) {
            2 => $this->handleConnack($body),
            3 => $this->handlePublish($flags, $body),
            9 => $this->SendDebug('MQTT', 'SUBACK empfangen', 0),
            13 => $this->SendDebug('MQTT', 'PINGRESP empfangen', 0),
            default => $this->SendDebug('MQTT', 'Pakettyp empfangen: ' . $packetType, 0)
        };
    }

    private function handleConnack(string $body): void
    {
        if (strlen($body) < 2) {
            $this->SendDebug('MQTT', 'CONNACK unvollstaendig', 0);
            return;
        }

        $returnCode = ord($body[1]);
        if ($returnCode !== 0) {
            $this->SendDebug('MQTT', 'CONNACK Fehlercode: ' . $returnCode, 0);
            return;
        }

        $this->WriteAttributeBoolean('MqttConnected', true);
        $this->SetTimerInterval('KeepAliveTimer', max(15, $this->ReadPropertyInteger('KeepAliveInterval')) * 500);
        $this->SendDebug('MQTT', 'CONNACK OK', 0);
        if ($this->ReadPropertyBoolean('AutoSubscribe')) {
            $this->SubscribeMqtt();
        }
    }

    private function handlePublish(int $flags, string $body): void
    {
        if (strlen($body) < 2) {
            return;
        }

        $topicLength = unpack('n', substr($body, 0, 2))[1];
        if (strlen($body) < 2 + $topicLength) {
            return;
        }

        $topic = substr($body, 2, $topicLength);
        $payloadOffset = 2 + $topicLength;
        $qos = ($flags & 0x06) >> 1;

        if ($qos > 0) {
            $payloadOffset += 2;
        }

        $payload = substr($body, $payloadOffset);
        $this->SendDebug('MQTT Topic', $topic, 0);

        if ($this->topicMatches($topic)) {
            $this->ProcessPayload($payload);
        }
    }

    private function fixedHeaderLength(string $packet): int
    {
        $index = 1;
        do {
            $encodedByte = ord($packet[$index]);
            $index++;
        } while (($encodedByte & 128) !== 0 && $index < 5);

        return $index;
    }

    private function buildConnectPacket(string $clientID, string $username, string $password, int $keepAlive, int $protocolLevel): string
    {
        $flags = 0x02;
        if ($username !== '') {
            $flags |= 0x80;
        }

        if ($password !== '') {
            $flags |= 0x40;
        }

        if ($protocolLevel === 3) {
            $variableHeader = $this->mqttString('MQIsdp') . chr(3) . chr($flags) . pack('n', $keepAlive);
        } else {
            $variableHeader = $this->mqttString('MQTT') . chr(4) . chr($flags) . pack('n', $keepAlive);
        }

        $payload = $this->mqttString($clientID);

        if ($username !== '') {
            $payload .= $this->mqttString($username);
        }

        if ($password !== '') {
            $payload .= $this->mqttString($password);
        }

        return chr(0x10) . $this->encodeRemainingLength(strlen($variableHeader . $payload)) . $variableHeader . $payload;
    }

    private function buildSubscribePacket(string $topic): string
    {
        $packetID = $this->nextPacketIdentifier();
        $payload = pack('n', $packetID) . $this->mqttString($topic) . chr(0);
        return chr(0x82) . $this->encodeRemainingLength(strlen($payload)) . $payload;
    }

    private function nextPacketIdentifier(): int
    {
        $identifier = $this->ReadAttributeInteger('PacketIdentifier');
        $next = $identifier >= 65535 ? 1 : $identifier + 1;
        $this->WriteAttributeInteger('PacketIdentifier', $next);
        return $identifier;
    }

    private function mqttString(string $value): string
    {
        return pack('n', strlen($value)) . $value;
    }

    private function encodeRemainingLength(int $length): string
    {
        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 128;
            }
            $encoded .= chr($digit);
        } while ($length > 0);

        return $encoded;
    }

    private function topicMatches(string $topic): bool
    {
        $filter = trim($this->ReadPropertyString('MqttTopic'));
        if ($filter === '' || $topic === '') {
            return true;
        }

        $pattern = preg_quote($filter, '/');
        $pattern = str_replace(['\+', '\#'], ['[^/]+', '.*'], $pattern);
        return preg_match('/^' . $pattern . '$/', $topic) === 1;
    }

    private function encodeIpsBuffer(string $buffer): string
    {
        $encoded = '';
        $length = strlen($buffer);

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($buffer[$index]);
            if ($byte < 128) {
                $encoded .= chr($byte);
            } else {
                $encoded .= chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3F));
            }
        }

        return $encoded;
    }

    private function decodeIpsBuffer(string $buffer): string
    {
        $decoded = '';
        $length = strlen($buffer);

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($buffer[$index]);
            if ($byte < 128) {
                $decoded .= chr($byte);
                continue;
            }

            if ($index + 1 < $length) {
                $next = ord($buffer[$index + 1]);
                $decoded .= chr((($byte & 0x1F) << 6) | ($next & 0x3F));
                $index++;
            }
        }

        return $decoded;
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
                $this->metric('AMS Stufe', $this->formatNumeric($state['amsHumidityLevel']), '', 'humidityLevel'),
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
            'amsHumidityLevel' => $this->firstNumeric([
                $print['ams']['ams'][0]['humidity'] ?? null
            ]),
            'amsHumidity' => $this->firstNumeric([
                $print['ams']['ams'][0]['humidity_raw'] ?? null
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
            'PrintErrorCode' => $state['errorCode'],
            'PrintErrorText' => $state['errorText'],
            'AmsTemperature' => $state['amsTemperature'],
            'AmsHumidityLevel' => (int) $state['amsHumidityLevel'],
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
            'amsHumidityLevel' => 0.0,
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
