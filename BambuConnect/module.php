<?php

declare(strict_types=1);

class BambuConnect extends IPSModuleStrict
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
        ['ident' => 'PrintErrorText', 'name' => 'Druck Fehlertext', 'type' => 3, 'profile' => '']
    ];

    private const ADVANCED_VARIABLES = [
        ['ident' => 'AmsTemperature', 'name' => 'AMS Temperatur', 'type' => 2, 'profile' => '~Temperature'],
        ['ident' => 'AmsHumidity', 'name' => 'AMS Feuchte', 'type' => 1, 'profile' => '~Humidity']
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('PrinterSerial', '');
        $this->RegisterPropertyString('MqttTopic', 'device/{SERIAL}/report');
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('UserName', 'bblp');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('MqttProtocolLevel', 4);
        $this->RegisterPropertyString('SocketTransport', 'legacy');
        $this->RegisterPropertyInteger('KeepAliveInterval', 60);
        $this->RegisterPropertyBoolean('AutoConnect', false);
        $this->RegisterPropertyBoolean('AutoSubscribe', true);
        $this->RegisterPropertyBoolean('AutoReconnect', true);
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
        $this->RegisterAttributeString('NetworkBuffer', '');
        $this->RegisterAttributeBoolean('MqttConnected', false);
        $this->RegisterAttributeInteger('PacketIdentifier', 1);
        $this->RegisterAttributeInteger('LastPayloadTimestamp', 0);
        $this->RegisterAttributeInteger('LastReconnectAttempt', 0);
        $this->RegisterTimer('KeepAliveTimer', 0, 'BAMBU_MqttPing($_IPS["TARGET"]);');
        $this->RegisterTimer('ConnectionWatchdogTimer', 0, 'BAMBU_CheckConnection($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->MaintainStatusVariables();
        $this->syncStatusVariables($this->getState());
        $this->SetTimerInterval('KeepAliveTimer', 0);
        $this->SetTimerInterval('ConnectionWatchdogTimer', $this->ReadPropertyBoolean('AutoReconnect') ? 30000 : 0);

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
        if (!$this->ReadPropertyBoolean('AutoReconnect') || !$this->hasParent()) {
            return;
        }

        $reference = $this->ReadAttributeInteger('LastPayloadTimestamp');
        if ($reference <= 0) {
            $reference = $this->ReadAttributeInteger('LastReconnectAttempt');
        }

        if ($reference <= 0 || time() - $reference < max(120, $this->ReadPropertyInteger('KeepAliveInterval') * 2)) {
            return;
        }

        $lastAttempt = $this->ReadAttributeInteger('LastReconnectAttempt');
        if ($lastAttempt > 0 && time() - $lastAttempt < 60) {
            return;
        }

        $this->SendDebug('MQTT', 'Keine Payload mehr empfangen, Verbindung wird neu aufgebaut', 0);
        $this->ConnectMqtt();
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            $this->SendDebug('ReceiveData', 'Ungueltiges Datenpaket', 0);
            return '';
        }

        $buffer = $this->normalizeSocketBuffer($this->decodeIpsBuffer((string)($data['Buffer'] ?? '')));
        if ($buffer === '') {
            return '';
        }

        if (strlen($buffer) <= 64) {
            $this->SendDebug('RX HEX', $this->formatDebugHex($buffer), 0);
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

        if (!$this->isParentSocketOpen()) {
            $this->SendDebug('Socket', 'Client Socket ist nicht verbunden. Bitte Client-Socket-IO aktivieren und speichern.', 0);
            return false;
        }

        $clientID = trim($this->ReadPropertyString('ClientID'));
        if ($clientID === '') {
            $clientID = 'BambuConnect-' . $this->InstanceID;
        }

        $username = trim($this->ReadPropertyString('UserName'));
        $password = $this->ReadPropertyString('Password');
        if ($username === '' || $password === '') {
            $this->SendDebug('MQTT', 'Benutzername oder Passwort / Access Code fehlt', 0);
            return false;
        }

        $packet = $this->buildConnectPacket(
            $clientID,
            $username,
            $password,
            max(15, $this->ReadPropertyInteger('KeepAliveInterval')),
            $this->ReadPropertyInteger('MqttProtocolLevel')
        );

        $this->WriteAttributeBoolean('MqttConnected', false);
        $this->WriteAttributeString('NetworkBuffer', '');
        $this->WriteAttributeInteger('LastReconnectAttempt', time());
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

        $topic = $this->resolveMqttTopic();
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

        if (!$this->isParentSocketOpen()) {
            $this->SendDebug('Socket', 'Client Socket ist nicht verbunden, Senden uebersprungen', 0);
            return false;
        }

        try {
            if ($this->ReadPropertyString('SocketTransport') === 'dataflow') {
                $this->SendDebug('Socket', 'Sende per SendDataToParent', 0);
                $this->SendDataToParent(json_encode([
                    'DataID' => self::CLIENT_SOCKET_TX,
                    'Buffer' => $this->encodeIpsBuffer($buffer)
                ], JSON_UNESCAPED_UNICODE));
            } else {
                $this->SendDebug('Socket', 'Sende per CSCK_SendText', 0);
                @CSCK_SendText($connectionID, $buffer);
            }
        } catch (Throwable $exception) {
            $this->SendDebug('Socket', $exception->getMessage(), 0);
            return false;
        }

        return true;
    }

    private function isParentSocketOpen(): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $connectionID = (int)($instance['ConnectionID'] ?? 0);
        if ($connectionID <= 0) {
            return false;
        }

        $connection = IPS_GetInstance($connectionID);
        return (int)($connection['InstanceStatus'] ?? 0) === 102;
    }

    private function handleMqttBytes(string $bytes): void
    {
        $buffer = $this->readNetworkBuffer() . $bytes;

        while ($buffer !== '') {
            $packet = $this->extractMqttPacket($buffer);
            if ($packet === null) {
                break;
            }

            $this->handleMqttPacket($packet);
        }

        if (strlen($buffer) > 1000000) {
            $this->SendDebug('MQTT', 'Netzwerkbuffer verworfen, zu gross', 0);
            $buffer = '';
        }

        $this->WriteAttributeString('NetworkBuffer', bin2hex($buffer));
    }

    private function readNetworkBuffer(): string
    {
        $buffer = $this->ReadAttributeString('NetworkBuffer');
        if ($buffer === '') {
            return '';
        }

        if ((strlen($buffer) % 2) === 0 && ctype_xdigit($buffer)) {
            $decoded = hex2bin($buffer);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $buffer;
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
            $this->SendDebug('MQTT', 'CONNACK Fehlercode ' . $returnCode . ': ' . $this->mqttConnackErrorText($returnCode), 0);
            return;
        }

        $this->WriteAttributeBoolean('MqttConnected', true);
        $this->SetTimerInterval('KeepAliveTimer', max(15, $this->ReadPropertyInteger('KeepAliveInterval')) * 500);
        $this->SendDebug('MQTT', 'CONNACK OK', 0);
        if ($this->ReadPropertyBoolean('AutoSubscribe')) {
            $this->SubscribeMqtt();
        }
    }

    private function mqttConnackErrorText(int $returnCode): string
    {
        return match ($returnCode) {
            1 => 'Protokollversion wird nicht akzeptiert',
            2 => 'Client-ID wird nicht akzeptiert',
            3 => 'Server nicht verfuegbar',
            4 => 'Benutzername oder Passwort fehlerhaft',
            5 => 'Nicht autorisiert, Access Code pruefen',
            default => 'Unbekannter Fehler'
        };
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
            $this->processPayload($payload);
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
        $filter = $this->resolveMqttTopic();
        if ($filter === '' || $topic === '') {
            return true;
        }

        $pattern = preg_quote($filter, '/');
        $pattern = str_replace(['\+', '\#'], ['[^/]+', '.*'], $pattern);
        return preg_match('/^' . $pattern . '$/', $topic) === 1;
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

    private function normalizeSocketBuffer(string $buffer): string
    {
        $trimmed = preg_replace('/\s+/', '', $buffer);
        if ($trimmed === null || $trimmed === '') {
            return $buffer;
        }

        if ((strlen($trimmed) % 2) === 0 && ctype_xdigit($trimmed)) {
            $decoded = hex2bin($trimmed);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $buffer;
    }

    private function formatDebugHex(string $buffer): string
    {
        $hex = bin2hex($buffer);
        if (strlen($hex) > 512) {
            $hex = substr($hex, 0, 512) . '...';
        }

        return $hex . ' (' . strlen($buffer) . ' Bytes)';
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
