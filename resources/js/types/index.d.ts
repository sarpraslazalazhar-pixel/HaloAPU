export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface Unit {
    id: number;
    nama_unit: string;
    deskripsi: string | null;
    aktif: boolean;
    sub_units_count: number;
    created_at: string;
}

export interface SubUnit {
    id: number;
    unit_id: number;
    nama_layanan: string;
    deskripsi: string | null;
    aktif: boolean;
    unit: Unit;
    form_fields_count: number;
}

export interface OrgDivisi {
    id: number;
    nama_divisi: string;
    org_units_count: number;
}

export interface OrgUnit {
    id: number;
    nama_unit_organisasi: string;
    divisi_id: number;
    divisi: OrgDivisi;
}

export interface OrgJabatan {
    id: number;
    nama_jabatan: string;
}

export interface FormField {
    id: number;
    sub_unit_id: number;
    label: string;
    tipe_field: string;
    wajib: boolean;
    opsi: string[] | null;
    parent_field_id: number | null;
    trigger_value: string | null;
    urutan: number;
    child_fields?: FormField[];
}

export interface Ticket {
    id: number;
    user_id: number;
    divisi_id: number | null;
    org_unit_id: number | null;
    jabatan_id: number | null;
    unit_id: number;
    sub_unit_id: number;
    form_data: Record<string, any>;
    status: string;
    created_at: string;
    updated_at: string;
    unit?: Unit;
    sub_unit?: SubUnit;
    attachments?: TicketAttachment[];
    logs?: TicketLog[];
}

export interface TicketAttachment {
    id: number;
    ticket_id: number;
    field_id: number | null;
    file_path: string;
    original_name: string;
    mime_type: string | null;
    file_size: number | null;
}

export interface TicketLog {
    id: number;
    ticket_id: number;
    admin_id: number | null;
    aksi: string;
    catatan: string | null;
    timestamp: string;
}

export interface ReminderConfig {
    id: number;
    jenis_reminder: string;
    lead_time_value: number;
    channel_aktif: string[];
    aktif: boolean;
}

export interface NotificationData {
    judul: string;
    pesan: string;
    icon?: string;
    aksi_url?: string;
    prioritas?: string;
    snoozed?: boolean;
    done_at?: string;
    [key: string]: any;
}

export interface NotificationItem {
    id: string;
    type: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string;
}

export interface Csat {
    id: number;
    ticket_id: number;
    user_id: number;
    rating: number;
    komentar: string | null;
    created_at: string;
    updated_at: string;
    ticket?: Ticket;
    user?: {
        id: number;
        username: string;
        email: string;
    };
}

export interface SystemConfig {
    nama_sistem: string;
    logo_path: string | null;
    banner_path: string | null;
    email_admin: string | null;
    wa_api_key: string | null;
    wa_gateway_url: string | null;
    nomor_wa_utama: string | null;
    nomor_wa_fallback: string | null;
    jam_kerja: Record<string, [string, string] | null>;
}

declare module '@inertiajs/react' {
    interface PageProps {
        auth: {
            user?: {
                id: number;
                username: string;
                email: string;
                no_wa?: string;
                divisi_id?: number;
                org_unit_id?: number;
                jabatan_id?: number;
            };
            admin?: {
                id: number;
                username: string;
                email: string;
            };
        };
        flash: {
            message?: string;
            success?: string;
            error?: string;
        };
    }
}
