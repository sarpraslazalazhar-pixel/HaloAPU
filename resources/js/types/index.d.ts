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
 wajib_kembali?: boolean;
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

export type FormFieldValue = string | number | boolean | string[] | null | undefined;

export interface EquipmentLoanSummary {
 status_peminjaman: 'Menunggu Persetujuan' | 'Menunggu Diserahkan' | 'Sedang Dipinjam' | 'Belum Dikembalikan' | 'Dikembalikan';
 is_overdue_schedule: boolean;
 is_due_today: boolean;
 alat_list: string[];
 alat_text: string;
 rentang_waktu: string;
 tanggal_mulai_iso: string;
 tanggal_selesai_iso: string;
 sort_date: number;
}

export interface TicketAdminActor {
 id: number;
 name?: string | null;
 username?: string | null;
}

export interface Ticket {
 id: number;
 formatted_id?: string;
 user_id: number | null;
 created_by_admin_id?: number | null;
 assigned_admin_id?: number | null;
 divisi_id: number | null;
 org_unit_id: number | null;
 jabatan_id: number | null;
 unit_id: number;
 sub_unit_id: number;
 form_data: Record<string, FormFieldValue>;
 status: string;
 diserahkan_at?: string | null;
 diserahkan_oleh_admin_id?: number | null;
 penerima_alat_user_id?: number | null;
 penerima_alat?: string | null;
 kondisi_serah?: string | null;
 catatan_serah?: string | null;
 dikembalikan_at?: string | null;
 diterima_kembali_oleh_admin_id?: number | null;
 kondisi_kembali?: string | null;
 catatan_kembali?: string | null;
 lampiran_serah_count?: number;
 lampiran_kembali_count?: number;
 diserahkan_oleh_admin?: TicketAdminActor | null;
 diterima_kembali_oleh_admin?: TicketAdminActor | null;
 loan_summary?: EquipmentLoanSummary | null;
 can_serahkan?: boolean;
 can_kembalikan?: boolean;
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
 title?: string;
 message?: string;
 icon?: string;
 aksi_url?: string;
 url?: string;
 prioritas?: string;
 done_at?: string;
 [key: string]: string | number | boolean | null | undefined;
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
 remembered?: {
 web: boolean;
 admin: boolean;
 };
 };
 flash: {
 message?: string;
 success?: string;
 error?: string;
 };
 }
}
