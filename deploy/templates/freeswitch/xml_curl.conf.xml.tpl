<configuration name="xml_curl.conf" description="Ownswitch directory and dialplan">
  <bindings>
    <binding name="ownswitch">
      <param name="gateway-url" value="http://@@INTERNAL_HTTP_ADDR@@/internal/fs/xml?key=@@FS_XML_SECRET@@" bindings="directory|dialplan"/>
      <param name="timeout" value="8"/>
    </binding>
  </bindings>
</configuration>
