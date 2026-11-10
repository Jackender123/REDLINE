<?php
/** Shared service choices. Values preserve existing website booking records. */
function rl_service_page_options(string $page): array
{
    $options = [
        'services' => [
            'OIL & FILTER CHANGE' => 'OIL & FILTER CHANGE',
            'ENGINE DIAGNOSTIC SCAN' => 'ENGINE DIAGNOSTIC SCAN',
            'AIR FILTER REPLACEMENT' => 'AIR FILTER REPLACEMENT',
            'BRAKE PAD REPLACEMENT' => 'BRAKE PAD REPLACEMENT',
            'TRANSMISSION SERVICE' => 'TRANSMISSION SERVICE',
        ],
        'performance' => [
            'STAGE 1 - DAILY RESPONSE' => 'Stage 1 - Daily Response (ECU Tune & Intake)',
            'STAGE 2 - FAST ROAD' => 'Stage 2 - Fast Road (Exhaust & Intercooler)',
            'STAGE 3 - EXTREME OUTPUT' => 'Stage 3 - Extreme Output (Forced Induction & Turbo)',
            'CUSTOM CHASSIS & SUSPENSION' => 'Custom Chassis & Suspension Setup',
        ],
        'fabrication' => [
            'CUSTOM EXHAUST SYSTEMS' => 'CUSTOM EXHAUST SYSTEMS',
            'ROLL CAGE FABRICATION' => 'ROLL CAGE FABRICATION',
            'HEADERS & METALWORK' => 'HEADERS & METALWORK',
            'WIDEBODY CONVERSIONS' => 'WIDEBODY CONVERSIONS',
        ],
    ];
    return ($options[$page] ?? []) + ['OTHER / NOT SURE' => 'Other / Not sure'];
}

function rl_service_groups(): array
{
    $groups = [];
    foreach (['services' => 'Factory Service', 'performance' => 'Performance Upgrades', 'fabrication' => 'Custom Fabrication'] as $page => $label) {
        foreach (rl_service_page_options($page) as $value => $name) {
            if ($value === 'OTHER / NOT SURE') continue;
            $prefix = $page === 'services' ? '' : strtoupper($page) . ': ';
            $groups[$label][$prefix . $value] = $name;
        }
    }
    $groups['Other'] = ['OTHER / NOT SURE' => 'Other / Not sure'];
    return $groups;
}

function rl_service_catalog(): array
{
    return array_merge(...array_values(rl_service_groups()));
}
