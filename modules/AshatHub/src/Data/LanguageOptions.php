<?php
declare(strict_types=1);
namespace Data;

/**
 * Static language list for the project-language picker (used by views).
 * The empty key = "Auto" — let the coding agent choose the stack.
 */
final class LanguageOptions
{
    /** @return array<string, string> value → label */
    public static function all(): array
    {
        return [
            ''           => 'Auto (let ASHAT choose)',
            'JavaScript' => 'JavaScript',
            'TypeScript' => 'TypeScript',
            'Python'     => 'Python',
            'PHP'        => 'PHP',
            'Java'       => 'Java',
            'Go'         => 'Go',
            'Rust'       => 'Rust',
            'C'          => 'C',
            'C++'        => 'C++',
            'C#'         => 'C#',
            'Swift'      => 'Swift',
            'Ruby'       => 'Ruby',
            'Kotlin'     => 'Kotlin',
            'Dart'       => 'Dart',
            'Lua'        => 'Lua',
            'R'          => 'R',
            'SQL'        => 'SQL',
            'HTML/CSS'   => 'HTML/CSS',
            'Shell/Bash' => 'Shell/Bash',
            'YAML'       => 'YAML',
            'Markdown'   => 'Markdown',
        ];
    }
}
