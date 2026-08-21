/**
 * Sequential row numbers for the admin panel's data tables.
 *
 * Every table draws a leading '#' column, and all of them get their number
 * from here so the rule is written once.
 *
 * The `item.<key>` slot hands you an `index` that counts **within the visible
 * page**, so page 2 restarts at 1 if you print it directly. Add the offset of
 * the page being shown and it keeps counting. That needs the table's real page
 * and page size, which means binding them — `v-model:page` and
 * `v-model:items-per-page` on a client-paged table, or the refs already driving
 * a `v-data-table-server`.
 *
 * "All rows" is -1 in Vuetify, and there is only ever one page of it, so the
 * offset is zero rather than a negative multiple.
 *
 * The number describes a position in the table as currently sorted and
 * filtered — it is not an id and does not travel with a row. Sorting a column
 * renumbers from the top, which is the point: it says "fourth of these", and a
 * database key with gaps in it would have people reading a deleted record into
 * a missing number.
 */
import { unref } from 'vue'
import type { Ref } from 'vue'

type MaybeRef<T> = T | Ref<T>

export function useRowNumber(page: MaybeRef<number>, itemsPerPage: MaybeRef<number>) {
  return (index: number): number => {
    const perPage = unref(itemsPerPage)
    const offset = perPage === -1 ? 0 : (unref(page) - 1) * perPage
    return offset + index + 1
  }
}
