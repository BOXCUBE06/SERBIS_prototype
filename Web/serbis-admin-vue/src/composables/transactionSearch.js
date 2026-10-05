// One reading of a typed transaction number for every list that searches by it.
// The panel prints ids as TXN-000222 (requests) or BOR-000042 (borrowings), but
// people type the bare number, the padded number or the full label.

/** The numeric id a typed query names, or null when the text is not a number. Case-insensitive. */
export const transactionId = (query) => {
  const match = /^(?:txn|bor)?[\s-]*(\d+)$/i.exec(String(query ?? '').trim())
  // Number() drops the leading zeros: "000222" is 222.
  return match ? Number(match[1]) : null
}

/** Whether `query` names `id`: "222", "000222", "TXN-000222" and "txn-222" all match 222. */
export const matchesTransaction = (id, query) => {
  const wanted = transactionId(query)
  return wanted !== null && id !== null && id !== undefined && Number(id) === wanted
}
