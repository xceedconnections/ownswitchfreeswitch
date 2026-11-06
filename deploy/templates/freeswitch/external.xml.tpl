<profile name="external">
  <!-- Ownswitch SIP edge. Replaces OpenSIPS on @@SIP_PORT@@. -->
  <settings>
    <param name="debug" value="0"/>
    <param name="sip-trace" value="no"/>
    <param name="sip-capture" value="no"/>
    <param name="rfc2833-pt" value="101"/>
    <param name="sip-port" value="@@SIP_PORT@@"/>
    <param name="dialplan" value="XML"/>
    <param name="context" value="ownswitch"/>
    <param name="dtmf-duration" value="2000"/>
    <param name="inbound-codec-prefs" value="PCMU,PCMA,OPUS,G722"/>
    <param name="outbound-codec-prefs" value="PCMU,PCMA,OPUS,G722"/>
    <param name="rtp-timer-name" value="soft"/>
    <param name="rtp-ip" value="$${local_ip_v4}"/>
    <param name="sip-ip" value="$${local_ip_v4}"/>
    <param name="hold-music" value="local_stream://moh"/>
    <param name="apply-nat-acl" value="nat.auto"/>
    <param name="aggressive-nat-detection" value="true"/>
    <param name="NDLB-received-in-nat-reg-contact" value="true"/>
    <param name="NDLB-force-rport" value="true"/>
    <param name="inbound-late-negotiation" value="true"/>
    <param name="auth-calls" value="true"/>
    <param name="auth-all-packets" value="false"/>
    <!-- WARNING lines for wrong passwords and unknown users. fail2ban reads auth.log. -->
    <param name="log-auth-failures" value="true"/>
    <param name="accept-blind-reg" value="false"/>
    <!-- REGISTER To/Contact user must equal the Authorization username (Asterisk match_auth_username). -->
    <param name="inbound-reg-force-matching-username" value="true"/>
    <!-- OPTIONS to every registered contact. ping-time is the Live column, in the SIP path. -->
    <param name="all-reg-options-ping" value="true"/>
    <param name="nat-options-ping" value="true"/>
    <param name="unregister-on-options-fail" value="false"/>
    <param name="ext-rtp-ip" value="@@PUBLIC_IP@@"/>
    <param name="ext-sip-ip" value="@@PUBLIC_IP@@"/>
    <param name="rtp-timeout-sec" value="300"/>
    <param name="rtp-hold-timeout-sec" value="1800"/>
    <param name="manage-presence" value="false"/>
    <param name="inbound-codec-negotiation" value="generous"/>
    <param name="nonce-ttl" value="60"/>
    <param name="tls" value="false"/>
    <param name="local-network-acl" value="localnet.auto"/>
  </settings>
  <gateways>
    <!-- Paths here are relative to this file (sip_profiles/), so external/*.xml
         → conf/sip_profiles/external/gw-*.xml. Do NOT use sip_profiles/external/*.xml
         (that doubles the folder) or absolute globs (unreliable on 1.10). -->
    <X-PRE-PROCESS cmd="include" data="external/*.xml"/>
  </gateways>
</profile>
