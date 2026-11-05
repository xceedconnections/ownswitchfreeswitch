<configuration name="xml_cdr.conf" description="Ownswitch CDR">
  <settings>
    <param name="url" value="http://@@INTERNAL_HTTP_ADDR@@/internal/fs/cdr?key=@@FS_XML_SECRET@@"/>
    <param name="encode" value="true"/>
    <param name="retries" value="2"/>
    <param name="delay" value="5"/>
    <param name="log-dir" value=""/>
    <param name="err-log-dir" value="@@FS_PREFIX@@/log/xml_cdr"/>
  </settings>
</configuration>
