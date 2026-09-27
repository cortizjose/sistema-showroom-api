# -*- coding: utf-8 -*-
"""
scripts/generar-pdf-pruebas.py
-------------------------------------------------------------------------------
Genera el documento de pruebas de la evidencia GA7-220501096-AA5-EV04 a partir
de docs/05-documento-de-pruebas.md y las capturas de docs/capturas/.

Requisitos:  pip install markdown Pillow

Uso:
    python scripts/generar-pdf-pruebas.py

Produce docs/_pruebas_para_pdf.html. Para convertirlo en PDF:

    chrome --headless=new --no-pdf-header-footer
           --print-to-pdf="GA7-220501096-AA5-EV04_Documento_de_pruebas.pdf"
           "file:///<ruta-absoluta>/docs/_pruebas_para_pdf.html"
"""

import glob
import io
import os
import re

import markdown
from PIL import Image

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOCS = os.path.join(RAIZ, "docs")
ENTRADA = os.path.join(DOCS, "05-documento-de-pruebas.md")
SALIDA = os.path.join(DOCS, "_pruebas_para_pdf.html")
COMPRIMIDAS = os.path.join(DOCS, "_capturas_comprimidas")

AUTOR = "José Cortés"
PROGRAMA = "Análisis y Desarrollo de Software"
FECHA = "27 de septiembre de 2026"

ANCHO_MAX = 1500
CALIDAD = 80

# -----------------------------------------------------------------------------
# 1. Las capturas se reducen y se pasan a JPEG: el PDF baja de varios MB a
#    menos de uno, sin que el texto de Postman deje de leerse.
# -----------------------------------------------------------------------------
os.makedirs(COMPRIMIDAS, exist_ok=True)

for ruta in sorted(glob.glob(os.path.join(DOCS, "capturas", "*.png"))):
    nombre = os.path.splitext(os.path.basename(ruta))[0]
    salida = os.path.join(COMPRIMIDAS, nombre + ".jpg")

    img = Image.open(ruta).convert("RGB")
    if img.width > ANCHO_MAX:
        alto = round(img.height * ANCHO_MAX / img.width)
        img = img.resize((ANCHO_MAX, alto), Image.LANCZOS)

    img.save(salida, "JPEG", quality=CALIDAD, optimize=True, progressive=True)

# -----------------------------------------------------------------------------
# 2. Conversión del Markdown
# -----------------------------------------------------------------------------
texto = io.open(ENTRADA, encoding="utf-8").read()

corte = texto.find("## 1. Objetivo")
cuerpo = texto[corte:] if corte > 0 else texto


def absolutizar(match):
    """Convierte la ruta de cada imagen en un URI absoluto, prefiriendo la
    versión comprimida cuando existe."""
    alt, ruta = match.group(1), match.group(2)

    completa = os.path.join(DOCS, ruta.replace("/", os.sep))
    nombre = os.path.splitext(os.path.basename(completa))[0]
    comprimida = os.path.join(COMPRIMIDAS, nombre + ".jpg")

    if os.path.isfile(comprimida):
        completa = comprimida

    uri = "file:///" + completa.replace("\\", "/").replace(" ", "%20")
    return "![%s](%s)" % (alt, uri)


cuerpo = re.sub(r"!\[([^\]]*)\]\(([^)]+)\)", absolutizar, cuerpo)

# Los enlaces a otros documentos del repositorio no existen dentro del PDF.
cuerpo = re.sub(r"\[`?([^\]]+?)`?\]\((?:\.\./)?[^)]*\.(?:md|txt)\)", r"`\1`", cuerpo)

contenido = markdown.markdown(
    cuerpo,
    extensions=["tables", "fenced_code", "sane_lists", "attr_list", "nl2br"],
)

contenido = contenido.replace("<p><img", '<p class="figura"><img')

PLANTILLA = """<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>GA7-220501096-AA5-EV04 — Documento de pruebas</title>
<style>
  @page {{ size: A4; margin: 18mm 16mm 20mm 16mm; }}
  @page :first {{ margin: 0; }}

  * {{ box-sizing: border-box; }}
  body {{
    font-family: "Segoe UI", Calibri, Arial, sans-serif;
    font-size: 10.5pt; line-height: 1.55; color: #1a1a1a; margin: 0;
  }}

  .portada {{
    height: 297mm; padding: 45mm 25mm 25mm 25mm;
    page-break-after: always; border-top: 14mm solid #1f5fd6; position: relative;
  }}
  .portada .etiqueta {{
    font-size: 10pt; letter-spacing: 2.2px; text-transform: uppercase;
    color: #1f5fd6; font-weight: 700; margin-bottom: 10mm;
  }}
  .portada h1 {{ font-size: 27pt; line-height: 1.22; margin: 0 0 7mm; color: #10203a; }}
  .portada .subtitulo {{ font-size: 13pt; color: #44536b; margin: 0 0 16mm; line-height: 1.45; }}
  .portada .linea {{ width: 55mm; height: 3px; background: #1f5fd6; margin-bottom: 14mm; }}
  .portada dl {{ margin: 0; font-size: 11pt; }}
  .portada dt {{
    color: #6a7686; font-size: 8.5pt; text-transform: uppercase;
    letter-spacing: 1.1px; margin-top: 7mm;
  }}
  .portada dd {{ margin: 1.5mm 0 0; font-weight: 600; color: #10203a; }}
  .portada .pie {{
    position: absolute; bottom: 25mm; left: 25mm; right: 25mm;
    padding-top: 5mm; border-top: 1px solid #d5dce6; font-size: 9pt; color: #6a7686;
  }}
  .resultado {{
    margin-top: 14mm; padding: 6mm 7mm; background: #eef4ff;
    border-left: 4px solid #1f5fd6; font-size: 10.5pt; color: #10203a;
  }}
  .resultado strong {{ color: #0f7b46; }}

  h2 {{
    font-size: 15pt; color: #10203a; margin: 9mm 0 3.5mm;
    padding-bottom: 2mm; border-bottom: 2px solid #1f5fd6; page-break-after: avoid;
  }}
  h3 {{ font-size: 12pt; color: #1f3f77; margin: 6.5mm 0 2.5mm; page-break-after: avoid; }}
  p {{ margin: 0 0 3mm; text-align: justify; }}

  table {{
    width: 100%; border-collapse: collapse; margin: 3.5mm 0 5mm;
    font-size: 8.6pt; page-break-inside: avoid;
  }}
  th {{ background: #1f5fd6; color: #fff; text-align: left; padding: 2mm 2.5mm; font-weight: 600; }}
  td {{ border: 1px solid #d5dce6; padding: 1.8mm 2.5mm; vertical-align: top; }}
  tr:nth-child(even) td {{ background: #f7f9fc; }}

  code {{
    font-family: Consolas, "Courier New", monospace; font-size: 9pt;
    background: #f1f4f9; padding: 0.4mm 1.2mm; border-radius: 2px; color: #b3261e;
  }}
  pre {{
    background: #12192a; color: #dce6f5; padding: 4mm; border-radius: 2mm;
    font-size: 7.4pt; line-height: 1.42; page-break-inside: avoid;
    white-space: pre-wrap; word-break: break-all; overflow: hidden;
  }}
  pre code {{ background: none; color: inherit; padding: 0; font-size: inherit; }}

  blockquote {{
    margin: 3.5mm 0; padding: 3mm 4mm; background: #fff8e6;
    border-left: 3px solid #e0a800; font-size: 9.6pt;
  }}
  blockquote p {{ margin: 0; }}

  .figura {{ page-break-inside: avoid; text-align: center; margin: 4mm 0 1mm; }}
  .figura img {{
    max-width: 100%; max-height: 105mm;
    border: 1px solid #c3ccda; border-radius: 1.5mm;
  }}
  .figura + p {{ text-align: center; page-break-before: avoid; }}
  .figura + p em {{
    display: block; font-size: 8.8pt; color: #55627a; text-align: center; margin-top: 1mm;
  }}

  hr {{ border: 0; border-top: 1px solid #dfe5ee; margin: 6mm 0; }}
  ul, ol {{ margin: 0 0 3mm; padding-left: 6mm; }}
  li {{ margin-bottom: 1.2mm; }}
  strong {{ color: #10203a; }}
</style>
</head>
<body>

<div class="portada">
  <div class="etiqueta">Servicio Nacional de Aprendizaje — SENA</div>
  <h1>Documento de pruebas<br>de la API</h1>
  <div class="subtitulo">
    Evidencia GA7-220501096-AA5-EV04<br>
    Actividad de aprendizaje GA7-220501096-AA5:<br>
    Crear servicios web, de acuerdo con el diseño
  </div>
  <div class="linea"></div>
  <dl>
    <dt>Aprendiz</dt><dd>{autor}</dd>
    <dt>Programa de formación</dt><dd>{programa}</dd>
    <dt>Proyecto</dt><dd>Sistema de Showroom y Ventas — API REST</dd>
    <dt>Herramienta de testing</dt><dd>Postman</dd>
    <dt>Fecha de entrega</dt><dd>{fecha}</dd>
  </dl>
  <div class="resultado">
    Resultado de la ejecución de la colección:<br>
    <strong>47 peticiones · 87 aserciones · 0 fallos</strong>
  </div>
  <div class="pie">
    Esta evidencia documenta el testing de la API desarrollada en la evidencia
    GA7-220501096-AA5-EV03.
  </div>
</div>

{contenido}

</body>
</html>
"""

html = PLANTILLA.format(autor=AUTOR, programa=PROGRAMA, fecha=FECHA, contenido=contenido)

io.open(SALIDA, "w", encoding="utf-8").write(html)

print("HTML generado:", SALIDA)
print("Tamano: %.1f KB" % (os.path.getsize(SALIDA) / 1024))
