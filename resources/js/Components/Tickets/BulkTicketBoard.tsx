import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import DynamicField, { isDynamicFieldVisible } from '@/Components/DynamicForm/DynamicField';
import { FormField, FormFieldValue } from '@/types';
import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DragStartEvent,
} from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { ArrowLeft, Copy, GripVertical, Paperclip, Plus, Trash2 } from 'lucide-react';

export interface BulkSubUnitOption {
  id: number;
  unit_id: number;
  nama_layanan: string;
  form_fields: FormField[];
}

export interface BulkUnitOption {
  id: number;
  nama_unit: string;
  sub_units: BulkSubUnitOption[];
}

export interface BulkTicketBoardProps {
  unitList: BulkUnitOption[];
  maxTickets: number;
  priorities?: string[];
  showPriority?: boolean;
  submitUrl: string;
  backUrl: string;
  singleCreateUrl?: string;
  singleCreateLabel?: string;
}

interface Row {
  id: number;
  unit_id: string;
  sub_unit_id: string;
  priority: string;
  form_data: Record<string, FormFieldValue>;
  attachment: File | null;
  attachment_target: string;
}

interface TicketPayload {
  unit_id: string;
  sub_unit_id: string;
  priority?: string;
  form_data: string;
  attachment?: File;
  attachment_target?: string;
}

type RowErrorMap = Record<string, string>;

interface ParsedServerErrors {
  form: string[];
  rows: Record<number, RowErrorMap>;
}

interface PendingConfirm {
  type: 'delete' | 'move';
  rowId: number;
  unitId?: string;
}

const UNASSIGNED = '';

const isUploadField = (field: FormField) =>
  field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file';

const isTextField = (field: FormField) =>
  field.tipe_field === 'teks_pendek' || field.tipe_field === 'teks_panjang';

const isFilledValue = (value: FormFieldValue): boolean => {
  if (value === null || value === undefined || value === false) return false;

  if (value === true) return true;

  if (Array.isArray(value)) return value.length > 0;

  return String(value).trim() !== '';
};

const parseServerErrors = (raw: Record<string, string>, order: number[]): ParsedServerErrors => {
  const form: string[] = [];
  const rows: Record<number, RowErrorMap> = {};

  Object.entries(raw).forEach(([key, message]) => {
    if (!message) return;

    if (!key.startsWith('tickets.')) {
      form.push(message);

      return;
    }

    const match = /^tickets\.(\d+)(?:\.(.*))?$/.exec(key);
    const rowId = match ? order[Number(match[1])] : undefined;

    if (!match || rowId === undefined) {
      form.push(message);

      return;
    }

    const field = match[2] ? match[2] : '_row';
    const existing = rows[rowId];
    rows[rowId] = {
      ...existing,
      [field === 'attachment_target' ? 'attachment' : field]: message,
    };
  });

  return { form, rows };
};

function TicketCard({
  row,
  columnUnitId,
  label,
  snippet,
  priority,
  showPriority,
  hasError,
  incomplete,
  onOpen,
}: {
  row: Row;
  columnUnitId: string;
  label: string;
  snippet: string | null;
  priority: string;
  showPriority: boolean;
  hasError: boolean;
  incomplete: boolean;
  onOpen: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: `card-${row.id}`,
    data: { rowId: row.id, unitId: columnUnitId },
  });

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform) }}
      className={`group rounded-lg border bg-white p-2.5 shadow-sm transition-shadow hover:shadow-md ${
        hasError ? 'border-red-400 ring-1 ring-red-200' : 'border-slate-200'
      } ${isDragging ? 'opacity-40' : ''}`}
    >
      <div className="flex items-start gap-1.5">
        <button
          type="button"
          aria-label={`Geser ${label}`}
          className="mt-0.5 cursor-grab touch-none rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus-visible:ring-2 focus-visible:ring-sky-400 active:cursor-grabbing"
          {...attributes}
          {...listeners}
        >
          <GripVertical aria-hidden="true" className="h-3.5 w-3.5" />
        </button>
        <button
          type="button"
          data-card-row={row.id}
          onClick={onOpen}
          className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
        >
          <span className="block truncate text-sm font-medium text-slate-900">{label}</span>
          <span className="mt-0.5 block truncate text-xs text-slate-600">
            {snippet ?? 'Belum ada isian teks'}
          </span>
        </button>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-1.5 pl-5">
        {showPriority && (
          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-700">
            {priority || 'Prioritas'}
          </span>
        )}
        {row.attachment && (
          <span className="inline-flex items-center gap-1 text-[11px] text-slate-600">
            <Paperclip aria-hidden="true" className="h-3 w-3" /> Lampiran
          </span>
        )}
        {hasError ? (
          <span className="text-[11px] font-semibold text-red-700">Perlu diperbaiki</span>
        ) : incomplete ? (
          <span className="text-[11px] font-medium text-amber-800">Belum lengkap</span>
        ) : (
          <span className="text-[11px] font-medium text-sky-700">Siap</span>
        )}
      </div>
    </div>
  );
}

export function BulkTicketBoard({
  unitList,
  maxTickets,
  priorities = [],
  showPriority = true,
  submitUrl,
  backUrl,
  singleCreateUrl,
  singleCreateLabel,
}: BulkTicketBoardProps) {
  const nextRowId = useRef(2);
  const submittingRef = useRef(false);
  const summaryRef = useRef<HTMLDivElement | null>(null);
  const fileInputs = useRef(new Map<number, HTMLInputElement>());

  const { props } = usePage<{ flash?: { error?: string } }>();
  const flashError = props.flash?.error;

  const defaultPriority = priorities.includes('Sedang') ? 'Sedang' : (priorities[0] ?? '');

  const makeRow = (id: number, unitId = UNASSIGNED): Row => ({
    id,
    unit_id: unitId,
    sub_unit_id: '',
    priority: defaultPriority,
    form_data: {},
    attachment: null,
    attachment_target: 'general',
  });

  const [rows, setRows] = useState<Row[]>([makeRow(1)]);
  const [openRowId, setOpenRowId] = useState<number | null>(null);
  const [draggingRowId, setDraggingRowId] = useState<number | null>(null);
  const [rowErrors, setRowErrors] = useState<Record<number, RowErrorMap>>({});
  const [formErrors, setFormErrors] = useState<string[]>([]);
  const [pendingConfirm, setPendingConfirm] = useState<PendingConfirm | null>(null);
  const [focusSummaryToken, setFocusSummaryToken] = useState(0);
  const [dialogAttempted, setDialogAttempted] = useState(false);

  const { post, processing, transform } = useForm<{ tickets: TicketPayload[] }>({ tickets: [] });

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor),
  );

  const unitsById = useMemo(() => {
    const map = new Map<string, BulkUnitOption>();
    unitList.forEach(unit => map.set(String(unit.id), unit));

    return map;
  }, [unitList]);

  const findSubUnit = (row: Row): BulkSubUnitOption | undefined =>
    unitsById.get(row.unit_id)?.sub_units.find(sub => String(sub.id) === row.sub_unit_id);

  const visibleUploads = (row: Row, values: Record<string, FormFieldValue> = row.form_data): FormField[] => {
    const subUnit = findSubUnit(row);

    if (!subUnit) return [];

    return subUnit.form_fields.filter(field => isUploadField(field) && isDynamicFieldVisible(field, values));
  };

  const resolvedTarget = (row: Row, uploads: FormField[]): string => {
    const required = uploads.filter(field => field.wajib);

    if (required.length === 1) return String(required[0].id);

    if (row.attachment_target !== 'general' && uploads.some(field => String(field.id) === row.attachment_target)) {
      return row.attachment_target;
    }

    return 'general';
  };

  const defaultTargetFor = (subUnit: BulkSubUnitOption | undefined): string => {
    const required = (subUnit?.form_fields ?? [])
      .filter(field => isUploadField(field) && field.wajib && isDynamicFieldVisible(field, {}));

    return required.length === 1 ? String(required[0].id) : 'general';
  };

  const hasMeaningfulData = (row: Row): boolean => {
    if (row.attachment) return true;

    const subUnit = findSubUnit(row);

    if (!subUnit) return Object.values(row.form_data).some(isFilledValue);

    return subUnit.form_fields.some(
      field => !isUploadField(field)
        && isDynamicFieldVisible(field, row.form_data)
        && isFilledValue(row.form_data[String(field.id)]),
    );
  };

  const rowIsComplete = (row: Row, errors: RowErrorMap | undefined): boolean => {
    if (errors && Object.keys(errors).length > 0) return false;

    const subUnit = findSubUnit(row);

    if (!subUnit) return false;

    const visibleFields = subUnit.form_fields.filter(field => isDynamicFieldVisible(field, row.form_data));

    const requiredMissing = visibleFields.some(
      field => field.wajib
        && !isUploadField(field)
        && !isFilledValue(row.form_data[String(field.id)]),
    );

    if (requiredMissing) return false;

    const uploads = visibleUploads(row);
    const requiredUploads = uploads.filter(field => field.wajib);

    if (requiredUploads.length > 1) return false;

    if (requiredUploads.length === 1) {
      return resolvedTarget(row, uploads) === String(requiredUploads[0].id) && row.attachment !== null;
    }

    return true;
  };

  const rowLabel = (row: Row): string => {
    const subUnit = findSubUnit(row);

    if (!subUnit) return 'Pilih layanan';

    return subUnit.nama_layanan;
  };

  const rowSnippet = (row: Row): string | null => {
    const subUnit = findSubUnit(row);

    if (!subUnit) return null;

    const field = subUnit.form_fields.find(
      candidate => isTextField(candidate)
        && isDynamicFieldVisible(candidate, row.form_data)
        && isFilledValue(row.form_data[String(candidate.id)]),
    );

    if (!field) return null;

    const value = String(row.form_data[String(field.id)] ?? '').trim();

    return value.length > 80 ? `${value.slice(0, 80)}…` : value;
  };

  const completeCount = rows.filter(row => rowIsComplete(row, rowErrors[row.id])).length;
  const catalogEmpty = unitList.every(unit => unit.sub_units.length === 0);
  const atLimit = rows.length >= maxTickets;
  const locked = processing;

  const badRows = rows.filter(row => {
    const errors = rowErrors[row.id];

    return errors && Object.keys(errors).length > 0;
  });

  const columns = useMemo(() => {
    const grouped: { id: string; title: string; hint: string | null; rows: Row[] }[] = unitList.map(unit => ({
      id: String(unit.id),
      title: unit.nama_unit,
      hint: unit.sub_units.length === 0 ? 'Belum ada jenis layanan aktif' : null,
      rows: rows.filter(row => row.unit_id === String(unit.id)),
    }));

    const loose = rows.filter(row => row.unit_id === UNASSIGNED);

    if (loose.length > 0) {
      grouped.unshift({ id: UNASSIGNED, title: 'Belum ditempatkan', hint: 'Geser kartu ke kanal layanan', rows: loose });
    }

    return grouped;
  }, [unitList, rows]);

  useEffect(() => {
    if (focusSummaryToken > 0) summaryRef.current?.focus();
  }, [focusSummaryToken]);

  useEffect(() => {
    if (flashError) summaryRef.current?.focus();
  }, [flashError]);

  const patchRow = (id: number, patch: Partial<Row>) => {
    setRows(current => current.map(row => (row.id === id ? { ...row, ...patch } : row)));
  };

  const clearRowFieldError = (id: number, field: string) => {
    setRowErrors(current => {
      const existing = current[id];

      if (!existing || !(field in existing)) return current;

      const next = { ...existing };
      delete next[field];

      if (Object.keys(next).length === 0) {
        const rest = { ...current };
        delete rest[id];

        return rest;
      }

      return { ...current, [id]: next };
    });
  };

  const clearRowErrors = (id: number) => {
    setRowErrors(current => {
      if (!(id in current)) return current;

      const rest = { ...current };
      delete rest[id];

      return rest;
    });
  };

  const applyUnitChange = (row: Row, unitId: string) => {
    patchRow(row.id, {
      unit_id: unitId,
      sub_unit_id: '',
      form_data: {},
      attachment: null,
      attachment_target: 'general',
    });
    clearRowErrors(row.id);
  };

  const requestUnitChange = (row: Row, unitId: string) => {
    if (unitId === row.unit_id) return;

    if (hasMeaningfulData(row)) {
      setPendingConfirm({ type: 'move', rowId: row.id, unitId });

      return;
    }

    applyUnitChange(row, unitId);
  };

  const changeSubUnit = (row: Row, subUnitId: string) => {
    const subUnit = unitsById.get(row.unit_id)?.sub_units.find(sub => String(sub.id) === subUnitId);

    patchRow(row.id, {
      sub_unit_id: subUnitId,
      form_data: {},
      attachment: null,
      attachment_target: defaultTargetFor(subUnit),
    });
    clearRowErrors(row.id);
  };

  const changeField = (row: Row, fieldId: number, value: FormFieldValue) => {
    const values = { ...row.form_data, [String(fieldId)]: value };
    const uploads = visibleUploads(row, values);
    const target = resolvedTarget(row, uploads);

    patchRow(row.id, {
      form_data: values,
      attachment_target: target,
      attachment: target === row.attachment_target ? row.attachment : null,
    });
    clearRowFieldError(row.id, `form_data.${fieldId}`);
  };

  const changeAttachmentTarget = (row: Row, target: string) => {
    patchRow(row.id, { attachment_target: target, attachment: null });
    const input = fileInputs.current.get(row.id);

    if (input) input.value = '';

    clearRowFieldError(row.id, 'attachment');
  };

  const chooseAttachment = (row: Row, file: File | null) => {
    patchRow(row.id, { attachment: file });
    clearRowFieldError(row.id, 'attachment');
  };

  const removeAttachment = (row: Row) => {
    patchRow(row.id, { attachment: null });
    const input = fileInputs.current.get(row.id);

    if (input) input.value = '';

    clearRowFieldError(row.id, 'attachment');
  };

  const addRow = (unitId: string) => {
    if (rows.length >= maxTickets) return;

    const row = makeRow(nextRowId.current++, unitId);
    setRows(current => [...current, row]);
    setDialogAttempted(false);
    setOpenRowId(row.id);
  };

  const duplicateRow = (source: Row) => {
    if (rows.length >= maxTickets) return;

    const row: Row = {
      ...makeRow(nextRowId.current++, source.unit_id),
      sub_unit_id: source.sub_unit_id,
      priority: source.priority,
      form_data: { ...source.form_data },
      attachment_target: resolvedTarget(source, visibleUploads(source)),
    };

    setRows(current => {
      const index = current.findIndex(item => item.id === source.id);
      const next = [...current];
      next.splice(index + 1, 0, row);

      return next;
    });
    setDialogAttempted(false);
    setOpenRowId(row.id);
  };

  const applyDelete = (row: Row) => {
    if (rows.length <= 1) {
      const replacement = makeRow(row.id, row.unit_id);
      setRows([replacement]);
      clearRowErrors(row.id);

      return;
    }

    const remaining = rows.filter(item => item.id !== row.id);
    clearRowErrors(row.id);
    setRows(remaining);
    setOpenRowId(current => (current === row.id ? null : current));
  };

  const requestDelete = (row: Row) => {
    if (rows.length <= 1 && !hasMeaningfulData(row)) {
      applyDelete(row);

      return;
    }

    if (hasMeaningfulData(row)) {
      setPendingConfirm({ type: 'delete', rowId: row.id });

      return;
    }

    applyDelete(row);
  };

  const buildTickets = (snapshot: Row[]): TicketPayload[] =>
    snapshot.map(row => {
      const payload: TicketPayload = {
        unit_id: row.unit_id,
        sub_unit_id: row.sub_unit_id,
        form_data: JSON.stringify(row.form_data),
      };

      if (showPriority) {
        payload.priority = row.priority;
      }

      if (row.attachment) {
        payload.attachment = row.attachment;
        payload.attachment_target = resolvedTarget(row, visibleUploads(row));
      }

      return payload;
    });

  const submit = (event: React.SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (submittingRef.current || locked) return;

    submittingRef.current = true;
    const snapshot = rows;
    const order = snapshot.map(row => row.id);

    transform(() => ({ tickets: buildTickets(snapshot) }));

    post(submitUrl, {
      forceFormData: true,
      preserveState: true,
      preserveScroll: true,
      onError: rawErrors => {
        // SAFETY: Inertia validation errors are flat key -> message string pairs.
        const parsed = parseServerErrors(rawErrors as Record<string, string>, order);
        setFormErrors(parsed.form);
        setRowErrors(parsed.rows);

        const firstBad = snapshot.find(row => parsed.rows[row.id] && Object.keys(parsed.rows[row.id]).length > 0);

        if (firstBad) setOpenRowId(firstBad.id);

        setFocusSummaryToken(token => token + 1);
      },
      onFinish: () => {
        submittingRef.current = false;
      },
    });
  };

  const confirmPending = () => {
    if (!pendingConfirm) return;

    const row = rows.find(item => item.id === pendingConfirm.rowId);

    if (!row) {
      setPendingConfirm(null);

      return;
    }

    if (pendingConfirm.type === 'delete') {
      applyDelete(row);
    } else if (pendingConfirm.type === 'move' && pendingConfirm.unitId !== undefined) {
      applyUnitChange(row, pendingConfirm.unitId);
    }

    setPendingConfirm(null);
  };

  const parseCardRowId = (rawId: string | number): number | null => {
    const idText = String(rawId);

    if (!idText.startsWith('card-')) return null;

    const parsed = Number(idText.slice('card-'.length));

    return Number.isNaN(parsed) ? null : parsed;
  };

  const onDragStart = (event: DragStartEvent) => {
    setDraggingRowId(parseCardRowId(event.active.id));
  };

  const onDragEnd = (event: DragEndEvent) => {
    setDraggingRowId(null);

    const rowId = parseCardRowId(event.active.id);
    const overId = event.over ? String(event.over.id) : '';

    if (rowId === null || !overId.startsWith('col:')) return;

    const row = rows.find(item => item.id === rowId);

    if (!row) return;

    requestUnitChange(row, overId.slice('col:'.length));
  };

  const openRow = openRowId === null ? undefined : rows.find(row => row.id === openRowId);
  const openRowErrors = openRow ? (rowErrors[openRow.id] ?? {}) : {};
  const openRowIndex = openRow ? rows.findIndex(row => row.id === openRow.id) + 1 : 0;
  const openSubUnit = openRow ? findSubUnit(openRow) : undefined;
  const openUploads = openRow ? visibleUploads(openRow) : [];
  const openRequiredUploads = openUploads.filter(field => field.wajib);
  const openTarget = openRow ? resolvedTarget(openRow, openUploads) : 'general';
  const openTargetField = openUploads.find(field => String(field.id) === openTarget);
  const openNonUploadFields = (openSubUnit?.form_fields ?? []).filter(field => !isUploadField(field));
  const openConfirm = pendingConfirm ? rows.find(row => row.id === pendingConfirm.rowId) : undefined;
  const confirmIndex = openConfirm ? rows.findIndex(row => row.id === openConfirm.id) + 1 : 0;

  const extraMessages = Object.entries(openRowErrors).filter(([key]) => {
    if (key === '_row' || key === 'form_data' || key === 'attachment') return false;

    if (!key.startsWith('form_data.')) return false;

    const fieldId = key.slice('form_data.'.length);

    return !(openSubUnit?.form_fields ?? []).some(field => String(field.id) === fieldId);
  });

  return (
    <div className="mx-auto w-full max-w-[1400px] px-1 py-2 sm:px-2">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <Link href={backUrl}>
              <Button type="button" variant="outline" size="sm" className="gap-1.5">
                <ArrowLeft className="h-3.5 w-3.5" /> Kembali
              </Button>
            </Link>
            {singleCreateUrl && singleCreateLabel && (
              <Link href={singleCreateUrl}>
                <Button type="button" variant="ghost" size="sm">
                  {singleCreateLabel}
                </Button>
              </Link>
            )}
          </div>
          <h1 className="text-2xl font-bold">Ajukan Banyak Tiket</h1>
          <p className="mt-1 text-sm text-slate-600">
            Susun kartu per kanal layanan (maksimal {maxTickets} tiket), buka kartu untuk mengisi, lalu kirim satu batch.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="rounded-md bg-slate-100 px-2 py-1 font-medium text-slate-800">
            {rows.length} / {maxTickets} tiket
          </span>
          <span className="rounded-md bg-sky-50 px-2 py-1 text-sky-800">
            Siap: {completeCount} dari {rows.length}
          </span>
        </div>
      </div>

      {catalogEmpty && (
        <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          Belum ada kanal dengan jenis layanan aktif. Hubungi pengelola master layanan sebelum mengajukan tiket.
        </div>
      )}

      <form noValidate onSubmit={submit}>
        {(badRows.length > 0 || formErrors.length > 0 || flashError) && (
          <div
            ref={summaryRef}
            tabIndex={-1}
            role="alert"
            aria-live="polite"
            className="mb-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 outline-none focus-visible:ring-3 focus-visible:ring-red-300"
          >
            <p className="font-semibold">Periksa tiket yang ditandai.</p>

            {flashError && <p className="mt-1">{flashError}</p>}

            {formErrors.length > 0 && (
              <ul className="mt-2 list-disc space-y-1 pl-5">
                {formErrors.map(message => <li key={message}>{message}</li>)}
              </ul>
            )}

            {badRows.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-2">
                {badRows.map(row => (
                  <button
                    key={row.id}
                    type="button"
                    onClick={() => {
                      setDialogAttempted(true);
                      setOpenRowId(row.id);
                    }}
                    className="rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-medium text-red-800 hover:bg-red-100 focus-visible:ring-2 focus-visible:ring-red-400"
                  >
                    {rowLabel(row)} · kartu
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        <DndContext sensors={sensors} onDragStart={onDragStart} onDragEnd={onDragEnd} onDragCancel={() => setDraggingRowId(null)}>
          <div className="-mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-3">
            {columns.map(column => (
              <BoardColumn
                key={column.id || 'unassigned'}
                id={column.id}
                title={column.title}
                hint={column.hint}
                count={column.rows.length}
                disabled={locked || atLimit || catalogEmpty}
                onAdd={() => addRow(column.id)}
              >
                {column.rows.map(row => {
                  const errors = rowErrors[row.id];
                  const hasError = !!errors && Object.keys(errors).length > 0;

                  return (
                    <TicketCard
                      key={row.id}
                      row={row}
                      columnUnitId={column.id}
                      label={rowLabel(row)}
                      snippet={rowSnippet(row)}
                      priority={row.priority}
                      showPriority={showPriority}
                      hasError={hasError}
                      incomplete={!rowIsComplete(row, errors)}
                      onOpen={() => {
                        setDialogAttempted(false);
                        setOpenRowId(row.id);
                      }}
                    />
                  );
                })}

                {column.rows.length === 0 && (
                  <p className="rounded-lg border border-dashed border-slate-300 px-2 py-4 text-center text-xs text-slate-500">
                    Belum ada kartu
                  </p>
                )}
              </BoardColumn>
            ))}

            {unitList.length === 0 && (
              <p className="w-full text-sm text-slate-600">Tidak ada kanal layanan aktif.</p>
            )}
          </div>

          <DragOverlay>
            {draggingRowId !== null && (() => {
              const row = rows.find(item => item.id === draggingRowId);

              if (!row) return null;

              return (
                <div className="w-64 rotate-1 rounded-lg border border-sky-300 bg-white p-2.5 shadow-lg">
                  <p className="truncate text-sm font-medium text-slate-900">{rowLabel(row)}</p>
                  <p className="mt-0.5 truncate text-xs text-slate-600">{rowSnippet(row) ?? 'Belum ada isian teks'}</p>
                </div>
              );
            })()}
          </DragOverlay>
        </DndContext>

        <div className="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => addRow(UNASSIGNED)}
              disabled={locked || atLimit || catalogEmpty}
            >
              <Plus className="h-4 w-4" /> Tambah Kartu
            </Button>
            {atLimit && <span className="text-xs text-amber-800">Batas {maxTickets} tiket tercapai.</span>}
          </div>

          <div className="flex items-center gap-3">
            <p className="text-xs text-slate-600" aria-live="polite">
              {processing ? 'Menyimpan semua tiket. Tunggu sebentar.' : 'Semua kartu dikirim dalam satu batch.'}
            </p>
            <Button
              type="submit"
              className="bg-sky-700 text-white hover:bg-sky-800"
              disabled={locked || catalogEmpty || rows.length < 1}
            >
              {processing ? 'Menyimpan…' : `Kirim ${rows.length} Tiket`}
            </Button>
          </div>
        </div>
      </form>

      <Dialog
        open={openRow !== undefined}
        onOpenChange={next => {
          if (!next) {
            setOpenRowId(null);
            setDialogAttempted(false);
          }
        }}
      >
        {openRow && (
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>{rowLabel(openRow)}</DialogTitle>
              <DialogDescription>
                Kartu {openRowIndex} dari {rows.length}
                {showPriority ? ` · ${openRow.priority || 'tanpa prioritas'}` : ''}
              </DialogDescription>
            </DialogHeader>

            {dialogAttempted && openRowErrors._row && (
              <p className="text-sm text-red-700">{openRowErrors._row}</p>
            )}
            {dialogAttempted && openRowErrors.form_data && (
              <p className="text-sm text-red-700">{openRowErrors.form_data}</p>
            )}
            {dialogAttempted && extraMessages.map(([key, message]) => (
              <p key={key} className="text-sm text-red-700">{message}</p>
            ))}

            <fieldset disabled={locked} className="min-w-0 space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1">
                  <Label htmlFor={`unit-${openRow.id}`}>Kanal Layanan *</Label>
                  <select
                    id={`unit-${openRow.id}`}
                    className="w-full rounded-md border border-gray-300 p-2"
                    value={openRow.unit_id}
                    aria-invalid={dialogAttempted && !!openRowErrors.unit_id}
                    onChange={event => requestUnitChange(openRow, event.target.value)}
                    required
                  >
                    <option value="">Pilih kanal...</option>
                    {unitList.map(option => (
                      <option key={option.id} value={option.id}>{option.nama_unit}</option>
                    ))}
                  </select>
                  {dialogAttempted && openRowErrors.unit_id && (
                    <p className="text-sm text-red-700">{openRowErrors.unit_id}</p>
                  )}
                </div>

                <div className="space-y-1">
                  <Label htmlFor={`sub-unit-${openRow.id}`}>Jenis Layanan *</Label>
                  <select
                    id={`sub-unit-${openRow.id}`}
                    className="w-full rounded-md border border-gray-300 p-2"
                    value={openRow.sub_unit_id}
                    disabled={!unitsById.get(openRow.unit_id)}
                    aria-invalid={dialogAttempted && !!openRowErrors.sub_unit_id}
                    onChange={event => changeSubUnit(openRow, event.target.value)}
                    required
                  >
                    <option value="">Pilih layanan...</option>
                    {(unitsById.get(openRow.unit_id)?.sub_units ?? []).map(option => (
                      <option key={option.id} value={option.id}>{option.nama_layanan}</option>
                    ))}
                  </select>
                  {dialogAttempted && openRowErrors.sub_unit_id && (
                    <p className="text-sm text-red-700">{openRowErrors.sub_unit_id}</p>
                  )}
                </div>

                {showPriority && (
                  <div className="space-y-1">
                    <Label htmlFor={`priority-${openRow.id}`}>Prioritas *</Label>
                    <select
                      id={`priority-${openRow.id}`}
                      className="w-full rounded-md border border-gray-300 p-2"
                      value={openRow.priority}
                      aria-invalid={dialogAttempted && !!openRowErrors.priority}
                      onChange={event => {
                        patchRow(openRow.id, { priority: event.target.value });
                        clearRowFieldError(openRow.id, 'priority');
                      }}
                      required
                    >
                      {priorities.map(priority => (
                        <option key={priority} value={priority}>{priority}</option>
                      ))}
                    </select>
                    {dialogAttempted && openRowErrors.priority && (
                      <p className="text-sm text-red-700">{openRowErrors.priority}</p>
                    )}
                  </div>
                )}
              </div>

              {openSubUnit && openNonUploadFields.length > 0 && (
                <DynamicField
                  fields={openNonUploadFields}
                  values={openRow.form_data}
                  onChange={(fieldId, value) => {
                    if (value instanceof File) return;

                    changeField(openRow, fieldId, Array.isArray(value)
                      ? value.map(item => String(item))
                      : value);
                  }}
                  errors={dialogAttempted ? openRowErrors : {}}
                />
              )}

              {openSubUnit && openSubUnit.form_fields.length === 0 && (
                <p className="text-sm text-slate-600">Layanan ini tidak memiliki isian tambahan.</p>
              )}

              <div className="space-y-2">
                <p className="text-sm font-medium text-gray-700">
                  Lampiran (maks. 2 MB{openTargetField ? `: ${openTargetField.label}` : ''})
                </p>

                {openRequiredUploads.length > 1 ? (
                  <p className="text-sm text-red-700">
                    Layanan ini memerlukan lebih dari satu lampiran. Ajukan tiket satu per satu.
                  </p>
                ) : (
                  <>
                    {openRequiredUploads.length === 0 && openUploads.length > 0 && (
                      <div className="space-y-1">
                        <Label htmlFor={`attachment-target-${openRow.id}`}>Tujuan Lampiran</Label>
                        <select
                          id={`attachment-target-${openRow.id}`}
                          className="w-full rounded-md border border-gray-300 p-2"
                          value={openTarget}
                          onChange={event => changeAttachmentTarget(openRow, event.target.value)}
                        >
                          <option value="general">Lampiran umum</option>
                          {openUploads.map(field => (
                            <option key={field.id} value={field.id}>{field.label}</option>
                          ))}
                        </select>
                      </div>
                    )}

                    <Label htmlFor={`attachment-${openRow.id}`} className="inline-flex w-fit">
                      {openRow.attachment ? 'Ganti lampiran' : 'Pilih lampiran'}
                    </Label>
                    <input
                      ref={element => {
                        if (element) fileInputs.current.set(openRow.id, element);
                        else fileInputs.current.delete(openRow.id);
                      }}
                      id={`attachment-${openRow.id}`}
                      type="file"
                      className="w-full"
                      accept={openTargetField?.tipe_field === 'upload_gambar'
                        ? 'image/*'
                        : openTargetField
                          ? '.jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.csv'
                          : 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv'}
                      aria-invalid={dialogAttempted && !!openRowErrors.attachment}
                      onChange={event => {
                        const file = event.target.files?.[0] ?? null;
                        event.currentTarget.value = '';
                        chooseAttachment(openRow, file);
                      }}
                    />

                    {openRow.attachment && (
                      <div className="flex flex-wrap items-center gap-3 text-sm text-slate-600">
                        <span className="break-all">{openRow.attachment.name}</span>
                        <Button type="button" size="sm" variant="outline" onClick={() => removeAttachment(openRow)}>
                          Hapus Lampiran
                        </Button>
                      </div>
                    )}
                  </>
                )}

                {dialogAttempted && openRowErrors.attachment && (
                  <p className="text-sm text-red-700">{openRowErrors.attachment}</p>
                )}
              </div>
            </fieldset>

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => duplicateRow(openRow)}
                  disabled={locked || atLimit}
                >
                  <Copy className="h-3.5 w-3.5" /> Duplikat
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => requestDelete(openRow)}
                  disabled={locked}
                >
                  <Trash2 className="h-3.5 w-3.5" /> Hapus
                </Button>
              </div>
              <Button type="button" onClick={() => setOpenRowId(null)}>
                Selesai
              </Button>
            </div>
          </DialogContent>
        )}
      </Dialog>

      <AlertDialog
        open={pendingConfirm !== null}
        onOpenChange={open => {
          if (!open) setPendingConfirm(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pendingConfirm?.type === 'delete'
                ? `Hapus tiket #${confirmIndex}?`
                : 'Pindahkan kartu?'}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {pendingConfirm?.type === 'delete'
                ? 'Isian dan lampiran pada kartu ini akan hilang.'
                : 'Isian dan lampiran kartu ini akan dikosongkan karena layanannya berubah.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Batal</AlertDialogCancel>
            <AlertDialogAction onClick={confirmPending}>
              {pendingConfirm?.type === 'delete' ? 'Hapus tiket' : 'Pindahkan'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function BoardColumn({
  id,
  title,
  hint,
  count,
  disabled,
  onAdd,
  children,
}: {
  id: string;
  title: string;
  hint: string | null;
  count: number;
  disabled: boolean;
  onAdd: () => void;
  children: React.ReactNode;
}) {
  const { setNodeRef, isOver } = useDroppable({ id: `col:${id}` });

  return (
    <section
      ref={setNodeRef}
      className={`flex w-[85vw] shrink-0 snap-start flex-col rounded-xl border bg-slate-100/70 sm:w-72 ${
        isOver ? 'border-sky-400 bg-sky-50' : 'border-slate-200'
      }`}
    >
      <header className="flex items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
        <h2 className="truncate text-sm font-semibold text-slate-900">{title}</h2>
        <span className="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-slate-700">{count}</span>
      </header>

      <div className="flex min-h-24 flex-1 flex-col gap-2 p-2">
        {children}

        {hint && (
          <p className="px-1 text-[11px] text-slate-500">{hint}</p>
        )}

        <button
          type="button"
          onClick={onAdd}
          disabled={disabled}
          className="mt-auto flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs font-medium text-slate-600 hover:bg-white hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-sky-400"
        >
          <Plus aria-hidden="true" className="h-3.5 w-3.5" /> Tambah tiket
        </button>
      </div>
    </section>
  );
}
