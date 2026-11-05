<?php

declare(strict_types=1);

namespace Ownswitch\Services\Media;

/**
 * Media handling modes:
 *   direct  - no media relay (endpoints exchange RTP; lowest load, no recording, no topology hiding)
 *   relay   - RTPengine relays RTP
 *   record  - RTPengine relay + recording (requires relay; must be allowed by customer flag)
 * Precedence (most specific wins): trunk > customer > global default.
 */
final class MediaPolicyService
{
    public const MODES = ['direct', 'relay', 'record'];

    public static function valid(?string $m): bool
    {
        return $m !== null && in_array($m, self::MODES, true);
    }

    /**
     * @return array{mode:string,source:string,relay:bool,record:bool,notes:list<string>}
     */
    public function resolve(?string $trunkPolicy, ?string $customerPolicy, string $globalDefault, bool $customerRecordingEnabled = false): array
    {
        $notes = [];
        [$mode, $source] = match (true) {
            self::valid($trunkPolicy)    => [$trunkPolicy, 'trunk'],
            self::valid($customerPolicy) => [$customerPolicy, 'customer'],
            default                      => [self::valid($globalDefault) ? $globalDefault : 'relay', 'global'],
        };
        if ($mode === 'record' && !$customerRecordingEnabled) {
            $mode = 'relay';
            $notes[] = 'Recording requested but not enabled for this customer: downgraded to relay.';
        }
        return ['mode' => $mode, 'source' => $source, 'relay' => $mode !== 'direct', 'record' => $mode === 'record', 'notes' => $notes];
    }

    /**
     * RTPengine ng-protocol "offer" flags for the resolved policy.
     *
     * @return array<string,mixed>
     */
    public function rtpengineFlags(array $resolved): array
    {
        if (!$resolved['relay']) {
            return [];   // direct: OpenSIPS does not call rtpengine at all
        }
        $flags = ['ICE' => 'remove', 'replace' => ['origin', 'session-connection']];
        if ($resolved['record']) {
            $flags['record-call'] = 'yes';
        }
        return $flags;
    }
}
