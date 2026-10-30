/**
 * Which files the document pickers offer.
 *
 * The server decides what is accepted, from each file's content
 * (App\Services\DocumentUploadPolicy); these lists only spare people from
 * choosing a file that would be refused. Web pages, scripts, programs and
 * archives are left out on purpose.
 */
export const DOCUMENT_ACCEPT = [
  '.pdf',
  '.jpg', '.jpeg', '.jpe', '.jfif', '.png', '.webp', '.gif', '.bmp', '.tif', '.tiff', '.heic', '.heif',
  '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.odt', '.ods', '.odp',
  '.rtf', '.txt', '.csv',
].join(',')

/** Document reading (OCR) understands PDFs and ordinary photos only. */
const READABLE = ['.pdf', '.jpg', '.jpeg', '.jpe', '.jfif', '.png', '.webp']

export const READABLE_ACCEPT = READABLE.join(',')

/** Whether document reading can look at this file, judged by its name. */
export function isReadable(file) {
  const name = file?.name?.toLowerCase() ?? ''
  return READABLE.some((extension) => name.endsWith(extension))
}
