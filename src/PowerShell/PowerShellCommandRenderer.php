<?php

declare(strict_types=1);

namespace DougKusanagi\LaravelLanShare\PowerShell;

final class PowerShellCommandRenderer
{
    public function render(string $script, string $fileName): string
    {
        $encodedScript = base64_encode("\xEF\xBB\xBF".$script);
        $escapedFileName = str_replace("'", "''", $fileName);

        return "\$scriptPath=[IO.Path]::Combine([IO.Path]::GetTempPath(),'{$escapedFileName}');[IO.File]::WriteAllBytes(\$scriptPath,[Convert]::FromBase64String('{$encodedScript}'));& \$scriptPath";
    }
}
