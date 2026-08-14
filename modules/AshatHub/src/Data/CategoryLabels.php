<?php
declare(strict_types=1);
namespace Data;

/**
 * Static label maps for categories (used by views).
 */
final class CategoryLabels
{
    public static function community(): array
    {
        return [
            'tools'    => 'Tools',
            'ai'       => 'AI & Inference',
            'pipeline' => 'Pipelines',
            'games'    => 'Games',
            'general'  => 'General',
        ];
    }

    public static function docs(): array
    {
        return [
            'concepts'  => 'Concepts',
            'workflow'  => 'Workflow',
            'pro'       => 'Pro Features',
            'community' => 'Community',
            'files'     => 'Files',
        ];
    }
}
