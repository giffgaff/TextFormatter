<?xml version="1.0" encoding="utf-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

	<xsl:output method="html" encoding="utf-8" />

	<!-- Generated by scripts/generateTemplateParserTests.php -->
	<!-- Void:M Empty:N Mode:H -->
	<xsl:template match="FOO">
		<xsl:element name="{local-name()}"><xsl:attribute name="id">foo</xsl:attribute>foo</xsl:element>
	</xsl:template>

</xsl:stylesheet>