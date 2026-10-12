import * as React from 'react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import DocumentFolders from '@/components/DocumentFolders'
import ApplicantDocuments from '@/components/ApplicantDocuments'

/**
 * The document library, by applicant first.
 *
 * The review panel asked for the documents view to be "based on the name, where
 * all documents for that applicant will be seen". So the library opens on a
 * list of applicants, and a name opens everything that person has on file.
 *
 * The view by kind of document is kept as a second tab, because "show me every
 * medical certificate" is still a real question - it is just not the first one.
 *
 * The open applicant and the tab live in the URL, so a refresh or the back
 * button keeps the reader where they were.
 */
export default function Documents() {
  const [searchParams, setSearchParams] = useSearchParams()
  const view = searchParams.get('view') === 'by-type' ? 'by-type' : 'by-applicant'
  const applicant = searchParams.get('applicant')

  function update(changes) {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
    setSearchParams(next)
  }

  return (
    <>
      <PageHeader
        title="Documents"
        description="Every applicant's documents, opened by name. Requirements checked at the counter are verified without a file and are listed with the applicant's checklist."
      />

      <Tabs value={view} onValueChange={(value) => update({ view: value === 'by-type' ? 'by-type' : null, applicant: null })}>
        <TabsList>
          <TabsTrigger value="by-applicant">By applicant</TabsTrigger>
          <TabsTrigger value="by-type">By document type</TabsTrigger>
        </TabsList>

        <TabsContent value="by-applicant">
          <ApplicantDocuments
            selectedId={applicant}
            onSelect={(id) => update({ applicant: id ? String(id) : null })}
          />
        </TabsContent>

        <TabsContent value="by-type">
          <DocumentFolders />
        </TabsContent>
      </Tabs>
    </>
  )
}
