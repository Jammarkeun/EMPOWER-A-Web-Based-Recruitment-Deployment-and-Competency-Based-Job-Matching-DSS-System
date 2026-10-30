import { useApi } from '@/hooks/useApi'

/**
 * The applicant-status vocabulary, as the server defines it.
 *
 * Screens that list or group statuses read them from here rather than keeping
 * their own copy (audit CQ-02): the applicant list once had a hand-typed list
 * of fifteen statuses that nothing kept in step with the lifecycle. Cached for
 * ten minutes, because the list only changes with a release.
 */
export function useApplicantStatuses({ enabled = true } = {}) {
  const { data, loading, error } = useApi('/applicant-statuses', undefined, {
    enabled,
    cacheKey: 'applicant-statuses',
    staleTime: 10 * 60_000,
  })

  return {
    statuses: data?.statuses ?? [],
    stages: data?.stages ?? [],
    // Where a deployment can be recorded from, as Settings narrow it today.
    // Empty until loaded, so nothing offers Deploy on a guess.
    deployable: data?.deployable ?? [],
    loading,
    error,
  }
}
