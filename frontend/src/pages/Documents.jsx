import * as React from 'react'
import { PageHeader } from '@/components/PageHeader'
import DocumentFolders from '@/components/DocumentFolders'

/**
 * The document library, reachable in one click from the sidebar.
 *
 * Previously the only way to reach an uploaded file was through the applicant
 * who submitted it, which is the wrong way round when the question is about the
 * document rather than the person — "show me the medical certificates" meant
 * opening applicants one at a time.
 */
export default function Documents() {
  return (
    <>
      <PageHeader
        title="Documents"
        description="Every uploaded document, grouped by kind. Requirements checked at the counter are verified without a file, so they do not appear here."
      />
      <DocumentFolders />
    </>
  )
}
