/**
 * Sequential row numbers for the admin panel's data tables.
 *
 * Every table draws a leading '#' column, and all of them get their number
 * from here so the rule is written once.
 *
 * The number is a position in the list the table was handed — not a database
 * key. Those keys have gaps in them, and printing one as "the number of the
 * record" would have people reading a deleted row into a missing number.
 */
import { computed, unref } from 'vue'
import type { Ref } from 'vue'

type Row = Record<string, any>
type MaybeRef<T> = T | Ref<T>

/**
 * Numbers a row by looking up where it sits in the full source list, keyed on
 * its own id.
 *
 * The table's `item.<key>` slot also offers an `index`, but that counts within
 * the visible page — printing it directly restarts at 1 on page 2. This never
 * touches it: the row carries its id into a map built once from the whole
 * list, so the number is the same whichever page the row is being drawn on.
 *
 * A consequence worth knowing before reusing this: the number belongs to the
 * row, not to the screen position. Sorting a column reorders the rows and the
 * numbers travel with them, so the column reads 7, 3, 12, 1 rather than
 * counting down the page. That is the intended behaviour here.
 *
 * @param source the same array bound to the table's `:items`
 * @param idKey  the same key given to the table's `item-value`
 */
export function useRowNumbers(source: MaybeRef<Row[]>, idKey: string) {
  const numberById = computed(() => {
    const map = new Map<unknown, number>()
    ;(unref(source) || []).forEach((row, i) => {
      const id = row?.[idKey] ?? row?.id
      if (id !== undefined && id !== null) map.set(id, i + 1)
    })
    return map
  })

  return (row: Row): number | string => {
    const id = row?.[idKey] ?? row?.id
    return numberById.value.get(id) ?? ''
  }
}

/**
 * The server-paged variant, for `v-data-table-server`.
 *
 * There is no full list to find a position in — the component only ever holds
 * the page the server returned — so the number has to be derived from which
 * page was asked for. Row identity cannot help here; nothing on the client
 * knows where row 7 of page 3 sits overall except the page arithmetic.
 */
export function useServerRowNumber(page: MaybeRef<number>, itemsPerPage: MaybeRef<number>) {
  return (index: number): number => {
    const perPage = unref(itemsPerPage)
    const offset = perPage === -1 ? 0 : (unref(page) - 1) * perPage
    return offset + index + 1
  }
}
