<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 설정: config/config.ini + 시크릿(config/secrets.ini 또는 환경 변수) (명세 19.7)
 *
 * - 시작할 때 ConfigSchema의 모든 키를 검사하고, 하나라도 틀리면 ConfigException으로 기동을 거부한다.
 *   오류 문장에는 "섹션.키: 이유"를 적는다. 값 자체는 적지 않는다(시크릿이 섞일 수 있으므로 형식 설명만).
 * - 크기는 바이트, 기간은 초 정수로 바꿔 보관한다.
 * - 시크릿은 secrets.ini보다 환경 변수(PJG_<키 대문자>)가 우선한다.
 * - 화면 설정(P27A): ConfigSchema::EDITABLE(허용 목록) 키만 config.ini 옆 settings.local.ini와 환경 변수 PJG_<섹션>_<키>로 덧씌운다.
 *   우선순위 환경 변수 > settings.local.ini > config.ini > 기본값. 덧씌운 값이 틀리면 그 키만 무시하고 warnings()에 남긴다(기동은 계속).
 */
final class Config
{
    public const SECRET_KEYS = ['encryption_key', 'encryption_key_previous', 'smtp_password', 'ldap_bind_password'];

    /** 화면에서 바꾼 값을 저장하는 파일 이름(P27A) — config.ini와 같은 폴더 */
    public const LOCAL_FILE = 'settings.local.ini';

    /** @var array<string, string> 편집 가능 키의 출처: default | config | screen | env */
    private array $sources = [];
    /** @var array<string, array{value: mixed, source: string}> 편집 가능 키의 화면 설정·환경 변수를 빼고 본 값(config.ini 또는 기본값) */
    private array $base = [];
    /** @var list<array{key: ?string, code: string}> 무시한 화면 설정·환경 변수(키만 무시하고 기동) */
    private array $warnings = [];
    private string $localVersion = '';
    private ?SettingsRegistry $registry = null;
    /** 다시 읽기(reload)에 쓰는 원본: config.ini 경로(파일에서 읽었을 때), 시크릿 경로, 환경 변수, INI 원문, 화면 설정 파일 경로 */
    private ?string $iniPath = null;
    private ?string $secretsPath = null;
    /** @var array<string, string> */
    private array $env = [];
    /** @var array<string, mixed> */
    private array $raw = [];
    private string $sourceName = 'config';
    private ?string $localPath = null;

    /**
     * @param array<string, array<string, mixed>> $values
     * @param array<string, string> $secrets
     */
    private function __construct(private array $values, private readonly array $secrets)
    {
    }

    /** 프로젝트 기본 위치(config/config.ini, config/settings.local.ini, config/secrets.ini, 환경 변수)에서 읽는다. */
    public static function fromRoot(string $root, ?SettingsRegistry $registry = null): self
    {
        $ini = $root . '/config/config.ini';
        if (!is_file($ini)) {
            throw new ConfigException(['config/config.ini 파일이 없습니다. config/config.ini.example을 복사해 만드세요'], $ini);
        }
        return self::load($ini, $root . '/config/secrets.ini', getenv(), $registry);
    }

    /**
     * config.ini + 같은 폴더의 settings.local.ini(화면 설정, P27A) + 시크릿 + 환경 변수.
     * 우선순위(편집 가능 키): 환경 변수 PJG_<섹션>_<키> > settings.local.ini > config.ini > 기본값.
     *
     * @param array<string, string> $env
     */
    public static function load(string $iniPath, ?string $secretsPath = null, array $env = [], ?SettingsRegistry $registry = null): self
    {
        $raw = @parse_ini_file($iniPath, true, INI_SCANNER_RAW);
        if ($raw === false) {
            $err = error_get_last();
            throw new ConfigException(['INI 문법 오류: ' . ($err['message'] ?? '알 수 없음')], basename($iniPath));
        }
        $localPath = dirname($iniPath) . DIRECTORY_SEPARATOR . self::LOCAL_FILE;
        [$local, $version, $fileOk] = self::readLocal($localPath);
        $config = self::fromArray($raw, self::loadSecrets($secretsPath, $env), basename($iniPath), $local, $env, $registry);
        $config->localVersion = $version;
        $config->iniPath = $iniPath;
        $config->secretsPath = $secretsPath;
        $config->localPath = $localPath;
        if (!$fileOk) {
            array_unshift($config->warnings, ['key' => null, 'code' => 'file_unreadable']);
        }
        return $config;
    }

    /** 읽은 config.ini 경로(배열로 만든 설정이면 null) — 백업 사본·설정 복원(P27B) */
    public function iniPath(): ?string
    {
        return $this->iniPath;
    }

    /** 화면 설정 파일 경로(config.ini 옆). 배열로 만든 설정(시험)은 withLocalFile()로 정하기 전까지 null */
    public function localPath(): ?string
    {
        return $this->localPath;
    }

    /** 같은 원본(INI 원문·시크릿·환경 변수·등록부)에 화면 설정 파일 $path를 덧씌운 새 설정 — 배열로 만든 설정(시험)용 */
    public function withLocalFile(string $path): self
    {
        [$local, $version, $ok] = self::readLocal($path);
        $c = self::fromArray($this->raw, $this->secrets, $this->sourceName, $local, $this->env, $this->registry);
        $c->localVersion = $version;
        $c->localPath = $path;
        $c->iniPath = $this->iniPath;
        $c->secretsPath = $this->secretsPath;
        if (!$ok) {
            array_unshift($c->warnings, ['key' => null, 'code' => 'file_unreadable']);
        }
        return $c;
    }

    /** 설정을 다시 읽는다(화면 저장 뒤·워커가 화면 설정 파일 변경을 볼 때). config.ini가 그사이 틀렸으면 ConfigException */
    public function reload(): self
    {
        if ($this->iniPath !== null) {
            return self::load($this->iniPath, $this->secretsPath, $this->env, $this->registry);
        }
        return $this->localPath !== null ? $this->withLocalFile($this->localPath) : $this;
    }

    /**
     * settings.local.ini 읽기: [섹션 → 키 → 원문], 버전 표지(내용 SHA-256, 파일이 없으면 ''), 읽기 성공 여부.
     * 문법이 깨졌으면 파일 전체를 무시한다(앱은 config.ini·기본값으로 기동, 화면에 경고).
     *
     * @return array{0: array<string, array<string, string>>, 1: string, 2: bool}
     */
    public static function readLocal(string $path): array
    {
        if (!is_file($path)) {
            return [[], '', true];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return [[], '', false];
        }
        $version = hash('sha256', $content);
        $parsed = @parse_ini_string($content, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            return [[], $version, false];
        }
        $out = [];
        foreach ($parsed as $section => $keys) {
            if (!is_array($keys)) {
                return [[], $version, false]; // 섹션 밖의 키 = 형식 오류
            }
            foreach ($keys as $k => $v) {
                if (!is_array($v)) {
                    $out[(string) $section][(string) $k] = self::unquote((string) $v);
                }
            }
        }
        return [$out, $version, true];
    }

    /**
     * @param array<string, mixed> $raw parse_ini_file(INI_SCANNER_RAW) 형태의 배열
     * @param array<string, string> $secrets
     * @param array<string, array<string, string>> $local settings.local.ini(화면 설정) — 편집 가능 키만 쓰고 나머지는 경고
     * @param array<string, string> $env 환경 변수 — 편집 가능 키는 PJG_<섹션>_<키>가 우선
     */
    public static function fromArray(array $raw, array $secrets = [], string $source = 'config', array $local = [], array $env = [], ?SettingsRegistry $registry = null): self
    {
        $schema = ConfigSchema::definition();
        $errors = [];
        $values = [];

        foreach ($raw as $section => $keys) {
            if (!is_array($keys)) {
                $errors[] = "{$section}: 섹션 밖의 키는 허용하지 않습니다";
                continue;
            }
            if (!isset($schema[$section])) {
                $errors[] = "[{$section}]: 알 수 없는 섹션입니다";
                continue;
            }
            foreach (array_keys($keys) as $key) {
                if (!isset($schema[$section][$key])) {
                    $errors[] = "{$section}.{$key}: 알 수 없는 키입니다(오타인지 확인)";
                }
            }
        }

        foreach ($schema as $section => $keys) {
            foreach ($keys as $key => $spec) {
                $name = "{$section}.{$key}";
                if (!isset($raw[$section]) || !is_array($raw[$section]) || !array_key_exists($key, $raw[$section])) {
                    // 나중 단계에서 추가한 키는 기본값(default)을 둬서, 그 키가 없는 기존 config.ini도 그대로 기동되게 한다
                    if (array_key_exists('default', $spec)) {
                        $values[$section][$key] = self::convert((string) $spec['default'], $spec);
                        continue;
                    }
                    $errors[] = "{$name}: 키가 없습니다";
                    continue;
                }
                $value = $raw[$section][$key];
                if (is_array($value)) {
                    $errors[] = "{$name}: 배열 값은 허용하지 않습니다";
                    continue;
                }
                try {
                    $values[$section][$key] = self::convert(trim(self::unquote((string) $value)), $spec);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = "{$name}: " . $e->getMessage();
                }
            }
        }

        if ($errors === []) {
            $errors = ConfigSchema::crossCheck($values);
        }
        if ($errors !== []) {
            throw new ConfigException($errors, $source);
        }
        $config = new self($values, $secrets);
        $config->raw = $raw;
        $config->env = $env;
        $config->sourceName = $source;
        $config->overlay($raw, $local, $env, $registry ?? SettingsRegistry::default());
        return $config;
    }

    /**
     * 편집 가능 키에 화면 설정(settings.local.ini)과 환경 변수를 덧씌운다(P27A). config.ini 검사가 끝난 뒤라 기본 상태는 항상 유효하고,
     * 덧씌운 값이 형식·범위·키 간 규칙에 어긋나면 **그 키만** 무시하고 경고로 남긴다(앱은 기동). 순서대로 하나씩 적용하며 매번 키 간 규칙을 다시 본다.
     *
     * @param array<string, mixed> $raw
     * @param array<string, array<string, string>> $local
     * @param array<string, string> $env
     */
    private function overlay(array $raw, array $local, array $env, SettingsRegistry $registry): void
    {
        $this->registry = $registry;
        foreach ($registry->all() as $name => $item) {
            [$section, $key] = explode('.', $name, 2);
            if (!SettingsRegistry::inSchema($name)) {
                // 시험용 더미 키: 형식 파일에 없으므로 기본값에서 시작
                $this->values[$section][$key] = self::convert((string) $item['spec']['default'], $item['spec']);
                $this->sources[$name] = 'default';
            } else {
                $this->sources[$name] = isset($raw[$section]) && is_array($raw[$section]) && array_key_exists($key, $raw[$section]) ? 'config' : 'default';
            }
            $this->base[$name] = ['value' => $this->values[$section][$key], 'source' => $this->sources[$name]];
        }
        // 키마다 덧씌울 값 하나(환경 변수 > settings.local.ini). 경고는 파일·키 순서대로 남긴다
        $candidates = [];
        $warnings = [];
        $order = 0;
        foreach ($local as $section => $keys) {
            foreach ($keys as $key => $value) {
                $name = "{$section}.{$key}";
                if (!$registry->has($name)) {
                    $warnings[$order++] = ['key' => preg_match('/^[a-z_]+\.[a-z_]+$/', $name) === 1 ? $name : null, 'code' => 'not_editable'];
                    continue;
                }
                $candidates[$name] = ['order' => $order++, 'value' => (string) $value, 'source' => 'screen'];
            }
        }
        foreach ($registry->all() as $name => $item) {
            $envName = SettingsRegistry::envName($name);
            if (isset($env[$envName]) && is_string($env[$envName]) && $env[$envName] !== '') {
                $candidates[$name] = ['order' => $candidates[$name]['order'] ?? $order++, 'value' => $env[$envName], 'source' => 'env'];
            }
        }
        // P32: 키 간 규칙은 적용 순서와 무관하게 — 한 번에 저장한 값들(예: 관리자 이메일 켜기 + SMTP 서버, 경고·차단 비율 함께 낮추기)이
        // 파일 순서(알파벳)로 하나씩 검사되면 앞 키가 아직 덧씌우지 않은 config.ini 값과 어긋나 버려졌다. 더 적용할 수 없을 때까지 여러 번 돌고,
        // 남은 것은 한꺼번에 맞으면 함께 적용, 아니면 그 키만 무시(cross_check 경고)한다.
        $pending = [];
        foreach ($candidates as $name => $c) {
            [$ok, $converted] = $this->convertFor($name, $c['value'], $registry);
            if (!$ok) {
                $warnings[$c['order']] = ['key' => $name, 'code' => $c['source'] === 'env' ? 'env_invalid' : 'invalid_value'];
                continue;
            }
            $pending[$name] = $c + ['converted' => $converted];
        }
        do {
            $progress = false;
            foreach ($pending as $name => $c) {
                if ($this->tryApply($name, $c['converted'], $c['source'])) {
                    unset($pending[$name]);
                    $progress = true;
                }
            }
        } while ($progress && $pending !== []);
        if ($pending !== []) {
            $before = $this->values;
            foreach ($pending as $name => $c) {
                [$section, $key] = explode('.', $name, 2);
                $this->values[$section][$key] = $c['converted'];
            }
            if (ConfigSchema::crossCheck($this->values) === []) {
                foreach ($pending as $name => $c) {
                    $this->sources[$name] = $c['source'];
                }
            } else {
                $this->values = $before;
                foreach ($pending as $name => $c) {
                    $warnings[$c['order']] = ['key' => $name, 'code' => 'cross_check'];
                }
            }
        }
        ksort($warnings);
        array_push($this->warnings, ...array_values($warnings));
    }

    /**
     * 덧씌울 값을 형식에 맞게 바꾼다
     *
     * @return array{0: bool, 1: mixed} [형식이 맞는가, 바꾼 값]
     */
    private function convertFor(string $name, string $value, SettingsRegistry $registry): array
    {
        try {
            return [true, self::convert(trim($value), $registry->get($name)['spec'])];
        } catch (\InvalidArgumentException) {
            return [false, null];
        }
    }

    /** 값 하나를 덧씌워 키 간 규칙을 본다 — 어긋나면 되돌리고 false */
    private function tryApply(string $name, mixed $converted, string $source): bool
    {
        [$section, $key] = explode('.', $name, 2);
        $before = $this->values[$section][$key];
        $this->values[$section][$key] = $converted;
        if (ConfigSchema::crossCheck($this->values) !== []) {
            $this->values[$section][$key] = $before;
            return false;
        }
        $this->sources[$name] = $source;
        return true;
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private static function loadSecrets(?string $path, array $env): array
    {
        $secrets = [];
        if ($path !== null && is_file($path)) {
            $s = @parse_ini_file($path, false, INI_SCANNER_RAW);
            if ($s === false) {
                throw new ConfigException(['시크릿 파일 문법 오류'], basename($path));
            }
            foreach ($s as $k => $v) {
                if (!in_array($k, self::SECRET_KEYS, true)) {
                    throw new ConfigException(["{$k}: 알 수 없는 시크릿 키입니다"], basename($path));
                }
                $secrets[$k] = self::unquote((string) $v);
            }
        }
        foreach (self::SECRET_KEYS as $k) {
            $envName = 'PJG_' . strtoupper($k);
            if (isset($env[$envName]) && $env[$envName] !== '') {
                $secrets[$k] = $env[$envName];
            }
        }
        return $secrets;
    }

    private static function unquote(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            return substr($v, 1, -1);
        }
        return $v;
    }

    /** @param array<string, mixed> $spec */
    private static function convert(string $v, array $spec): mixed
    {
        $optional = $spec['optional'] ?? false;
        if ($v === '') {
            if ($optional) {
                return in_array($spec['type'], ['list', 'email_list', 'ip_list'], true) ? [] : null;
            }
            throw new \InvalidArgumentException('값이 비어 있습니다');
        }
        $out = match ($spec['type']) {
            'size' => self::parseSize($v),
            'duration' => self::parseDuration($v),
            'int' => self::parseInt($v),
            'percent' => self::parseInt($v),
            'bool' => match (strtolower($v)) {
                'true', '1', 'on', 'yes' => true,
                'false', '0', 'off', 'no' => false,
                default => throw new \InvalidArgumentException('true 또는 false여야 합니다'),
            },
            'string' => $v,
            'enum' => in_array($v, $spec['values'], true) ? $v : throw new \InvalidArgumentException('다음 중 하나여야 합니다: ' . implode(', ', $spec['values'])),
            'list' => self::parseList($v),
            'url' => preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s]*)?$#', $v) === 1 ? rtrim($v, '/') : throw new \InvalidArgumentException('http:// 또는 https://로 시작하는 주소여야 합니다'),
            'ldap_url' => preg_match('#^ldaps?://[A-Za-z0-9.\-]+(:\d{1,5})?/?$#', $v) === 1 ? $v : throw new \InvalidArgumentException('ldap:// 또는 ldaps:// 주소여야 합니다'),
            'email_list' => array_map(static fn (string $e): string => filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : throw new \InvalidArgumentException('이메일 형식이 아닌 항목이 있습니다'), self::parseList($v)),
            'ip_list' => array_map(static fn (string $ip): string => self::isIpOrCidr($ip) ? $ip : throw new \InvalidArgumentException('IP 주소 또는 CIDR 형식이 아닌 항목이 있습니다'), self::parseList($v)),
            'timezone' => in_array($v, \DateTimeZone::listIdentifiers(), true) ? $v : throw new \InvalidArgumentException('알 수 없는 시간대입니다(예: Asia/Seoul)'),
            'ratio' => is_numeric($v) && (float) $v >= 0 && (float) $v <= 1 ? (float) $v : throw new \InvalidArgumentException('0과 1 사이의 수여야 합니다(예: 0.5)'),
            'semver' => preg_match('/^\d+\.\d+\.\d+$/', $v) === 1 ? $v : throw new \InvalidArgumentException('버전 형식(예: 20.19.0)이어야 합니다'),
            // P27B 시각(KST HH:MM)
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : throw new \InvalidArgumentException('시각 형식(HH:MM, 00:00~23:59)이어야 합니다'),
            // P32B 날짜와 시각(표시 시간대 YYYY-MM-DD HH:MM) — 실제로 있는 날짜만
            'datetime' => self::isDateTime($v) ? $v : throw new \InvalidArgumentException('날짜·시각 형식(YYYY-MM-DD HH:MM)이어야 합니다'),
            // P27A 경로(화면 설정): 형식만 본다 — 존재·쓰기·금지 위치는 저장할 때 Services\Settings\PathPolicy가 검사한다
            'path' => strlen($v) <= self::PATH_MAX && preg_match('/["\x00-\x1f\x7f]/', $v) !== 1 ? $v : throw new \InvalidArgumentException('경로 형식이 아닙니다'),
            default => throw new \LogicException('알 수 없는 형식: ' . $spec['type']),
        };
        if ($spec['type'] === 'percent' && ($out < 0 || $out > 100)) {
            throw new \InvalidArgumentException('0~100 사이여야 합니다');
        }
        if (is_int($out) && isset($spec['min']) && $out < $spec['min']) {
            throw new \InvalidArgumentException('너무 작습니다(최소 ' . self::describe($spec['min'], $spec['type']) . ')');
        }
        if (is_int($out) && isset($spec['max']) && $out > $spec['max']) {
            throw new \InvalidArgumentException('너무 큽니다(최대 ' . self::describe($spec['max'], $spec['type']) . ')');
        }
        return $out;
    }

    private static function describe(int $n, string $type): string
    {
        return match ($type) {
            'size' => self::formatSize($n),
            'duration' => self::formatDuration($n),
            default => (string) $n,
        };
    }

    // ---- 단위 해석 (공개: 다른 곳에서도 같은 규칙을 쓴다) ----

    /** "8MB", "2GB", "1.5GB", "512KB", "100B", "1048576" → 바이트 (1024 기준) */
    public static function parseSize(string $v): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)?$/i', trim($v), $m) !== 1) {
            throw new \InvalidArgumentException('크기 형식이 아닙니다(예: 8MB, 2GB)');
        }
        $mult = match (strtoupper($m[2] ?? 'B')) {
            'B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4,
        };
        if (!isset($m[2]) || $m[2] === '' || strtoupper($m[2]) === 'B') {
            if (str_contains($m[1], '.')) {
                throw new \InvalidArgumentException('바이트 값은 정수여야 합니다');
            }
        }
        return (int) round((float) $m[1] * $mult);
    }

    /** "30m", "4h", "60d", "1w", "90s", "3600" → 초 */
    public static function parseDuration(string $v): int
    {
        if (preg_match('/^(\d+)\s*(s|m|h|d|w)?$/i', trim($v), $m) !== 1) {
            throw new \InvalidArgumentException('기간 형식이 아닙니다(예: 30m, 4h, 60d)');
        }
        $mult = match (strtolower($m[2] ?? 's')) {
            's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800,
        };
        return (int) $m[1] * $mult;
    }

    private static function parseInt(string $v): int
    {
        if (preg_match('/^-?\d+$/', $v) !== 1) {
            throw new \InvalidArgumentException('정수여야 합니다');
        }
        return (int) $v;
    }

    /** @return list<string> */
    private static function parseList(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => $s !== ''));
    }

    private static function isDateTime(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $v);
        return $d !== false && $d->format('Y-m-d H:i') === $v;
    }

    private static function isIpOrCidr(string $v): bool
    {
        [$ip, $bits] = array_pad(explode('/', $v, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return ctype_digit($bits) && (int) $bits <= $max;
    }

    public static function formatSize(int $bytes): string
    {
        foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $u => $n) {
            if ($bytes >= $n && $bytes % $n === 0) {
                return intdiv($bytes, $n) . $u;
            }
        }
        return $bytes . 'B';
    }

    public static function formatDuration(int $sec): string
    {
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $u => $n) {
            if ($sec >= $n && $sec % $n === 0) {
                return intdiv($sec, $n) . $u;
            }
        }
        return $sec . 's';
    }

    // ---- 조회 ----

    /** "섹션.키"로 값을 읽는다. 등록되지 않은 키는 프로그래밍 오류다. */
    public function get(string $name): mixed
    {
        [$section, $key] = explode('.', $name, 2) + [1 => ''];
        if (!isset($this->values[$section]) || !array_key_exists($key, $this->values[$section])) {
            throw new \LogicException("등록되지 않은 설정 키: {$name}");
        }
        return $this->values[$section][$key];
    }

    public function int(string $name): int
    {
        $v = $this->get($name);
        if (!is_int($v)) {
            throw new \LogicException("정수가 아닌 설정 키: {$name}");
        }
        return $v;
    }

    public function bool(string $name): bool
    {
        return (bool) $this->get($name);
    }

    public function string(string $name): string
    {
        return (string) ($this->get($name) ?? '');
    }

    // ---- 화면 설정(P27A) ----

    /** path 형식 값의 최대 길이(바이트) — 백업 파일 이름을 붙여도 Windows 259자 안에 들게(가정) */
    public const PATH_MAX = 200;

    /** 형식·범위 검사 후 변환(화면 저장 검증용). 어긋나면 InvalidArgumentException(문장은 로그용) */
    public static function convertValue(string $value, array $spec): mixed
    {
        return self::convert(trim($value), $spec);
    }

    /** 편집 가능 키의 출처: default | config | screen | env */
    public function source(string $name): string
    {
        return $this->sources[$name] ?? throw new \LogicException("편집 가능 키가 아님: {$name}");
    }

    /** @return array{value: mixed, source: string} 화면 설정·환경 변수를 빼고 본 값(config.ini 또는 기본값) */
    public function base(string $name): array
    {
        return $this->base[$name] ?? throw new \LogicException("편집 가능 키가 아님: {$name}");
    }

    /** @return list<array{key: ?string, code: string}> 무시한 화면 설정·환경 변수 */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** settings.local.ini 내용의 SHA-256(없으면 '') — 동시 편집 감지·워커 반영 확인 */
    public function localVersion(): string
    {
        return $this->localVersion;
    }

    public function registry(): SettingsRegistry
    {
        return $this->registry ?? SettingsRegistry::default();
    }

    /** @return array<string, array<string, mixed>> 형식 변환이 끝난 전체 값(키 간 규칙 검사용) */
    public function allValues(): array
    {
        return $this->values;
    }

    /** 값을 설정 파일 표기로(화면 표시·settings.local.ini 쓰기): 기간 "7d", 크기 "8MB", 논리 "true" */
    public static function display(mixed $value, array $spec): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(', ', $value),
            $spec['type'] === 'duration' && is_int($value) => self::formatDuration($value),
            $spec['type'] === 'size' && is_int($value) => self::formatSize($value),
            default => (string) $value,
        };
    }

    /** 시크릿 값. 없으면 null. 로그에 남기지 않는다. */
    public function secret(string $name): ?string
    {
        if (!in_array($name, self::SECRET_KEYS, true)) {
            throw new \LogicException("등록되지 않은 시크릿: {$name}");
        }
        return $this->secrets[$name] ?? null;
    }
}
