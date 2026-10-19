<?php

const HOUND_CONSENT_VERSION = '2026-10-19';

function hound_consent_policy()
{
    return [
        'version' => HOUND_CONSENT_VERSION,
        'purpose' => 'Optional privacy demonstration',
        'retention' => 'Delete submitted data when the demonstration is complete; do not use it for profiling or contact.',
        'fields' => [
            'location' => 'Optional browser-provided location after a separate browser permission prompt',
            'browser' => 'Browser language, timezone, platform, screen dimensions, touch support, and coarse capability hints',
        ],
        'excluded' => [
            'Passwords, credentials, files, contacts, messages, keystrokes, camera, microphone, clipboard, browsing history, and persistent identifiers',
        ],
    ];
}
