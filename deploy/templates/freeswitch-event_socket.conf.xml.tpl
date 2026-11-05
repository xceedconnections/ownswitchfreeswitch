<configuration name="event_socket.conf" description="Socket Client">
  <!-- Managed by Ownswitch installer (deploy/stages/75_freeswitch.sh).
       ESL is bound to loopback ONLY and must never be exposed publicly. -->
  <settings>
    <param name="nat-map" value="false"/>
    <param name="listen-ip" value="127.0.0.1"/>
    <param name="listen-port" value="8021"/>
    <param name="password" value="@@FS_ESL_PASSWORD@@"/>
    <param name="apply-inbound-acl" value="loopback.auto"/>
  </settings>
</configuration>
