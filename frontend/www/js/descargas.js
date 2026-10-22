import { apiFile } from './api.js';
import { saveFile } from './native.js';

/**
 * Descargas de archivos generados por la API (reportes CSV, Excel y PDF, códigos QR…), iguales
 * en web, PWA y app nativa (Fase 12). En web se descarga; en Android e iOS se abre la hoja de
 * compartir del sistema para guardarlo en Archivos o enviarlo.
 */
export async function downloadFromApi(path, { headers = {}, accept = '*/*', fallbackName = 'archivo', title = 'Compartir archivo' } = {}) {
  const { blob, filename } = await apiFile(path, { headers, accept, fallbackName });
  return saveFile(blob, filename, title);
}

export { saveFile };
