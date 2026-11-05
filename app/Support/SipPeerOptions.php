<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Shared SIP peer option lists for Trunks and SIP Users.
 * Labels keep familiar Asterisk names; values map to OpenSIPS-backed behaviour where applicable.
 */
final class SipPeerOptions
{
    public const YES_NO = ['yes' => 'Yes', 'no' => 'No'];

    public const DTMF = [
        'RFC2833' => 'RFC2833 / telephone-event',
        'info' => 'SIP INFO',
        'inband' => 'Inband',
        'auto' => 'Auto',
    ];

    /** Allow (pass) or block DTMF toward the far end (INFO + telephone-event). */
    public const DTMF_PASS = [
        'yes' => 'Allow DTMF (press 1 works)',
        'no' => 'Block DTMF (keys ignored)',
    ];

    public const CALLER_ID_MODE = [
        'passthrough' => 'Passthrough (ViciDial / softphone From)',
        'fixed' => 'Fixed portal CallerID',
        'display_name' => 'Softphone Display Name as CallerID',
    ];

    public const PEER_TYPE = [
        'friend' => 'friend (softphone: register + call)',
        'peer' => 'peer (IP / PBX outbound)',
        'user' => 'user (register only)',
    ];

    /** How the endpoint authenticates to Ownswitch / OpenSIPS. */
    public const CONNECTION_MODE = [
        'dynamic' => 'Dynamic (REGISTER + username/password)',
        'ip' => 'IP based (fixed IP peer / PBX)',
    ];

    public const PROVIDER_TECH = [
        'sip' => 'sip',
        'iax2' => 'iax2',
    ];

    /** OpenSIPS: force_rport / received via nathelper; comedia ≈ symmetric RTP (relay). */
    public const NAT = [
        'no' => 'No',
        'force_rport' => 'force_rport',
        'comedia' => 'comedia',
        'force_rport,comedia' => 'force_rport,comedia',
    ];

    /** Trust remote port / skip digest on INVITE when IP is in address table. */
    public const INSECURE = [
        'no' => 'no',
        'port' => 'port',
        'invite' => 'invite',
        'port,invite' => 'port,invite',
    ];

    /** Individual codecs for multiselect chips (stored comma-separated). */
    public const CODECS = [
        'PCMU' => 'G.711 μ-law (ulaw)',
        'PCMA' => 'G.711 A-law (alaw)',
        'G729' => 'G.729',
        'GSM' => 'GSM',
        'opus' => 'Opus',
        'G722' => 'G.722',
        'iLBC' => 'iLBC',
        'speex' => 'Speex',
        'G723' => 'G.723',
        'G726' => 'G.726',
    ];
}
