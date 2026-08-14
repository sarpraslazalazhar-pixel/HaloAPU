import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';

class HelpScreen extends StatelessWidget {
  const HelpScreen({super.key});

  static const Map<String, List<Map<String, String>>> _faqData = {
    'Umum': [
      {
        'question': 'Apa itu Halo APU?',
        'answer': 'Halo APU adalah aplikasi helpdesk internal untuk memantau, merespons, dan mengajukan laporan tiket layanan dengan cepat dan mudah.'
      },
      {
        'question': 'Bagaimana alur penyelesaian tiket layanan?',
        'answer': 'Tiket yang diajukan akan diterima oleh departemen terkait (misal: IT Support atau HRD), dikerjakan sesuai SLA, lalu diselesaikan.'
      },
      {
        'question': 'Di mana saya bisa melihat riwayat rating yang saya berikan?',
        'answer': 'Anda dapat membuka menu "Rating" di navigasi bawah untuk melihat riwayat penilaian layanan yang pernah Anda berikan.'
      },
    ],
    'Teknis': [
      {
        'question': 'Bagaimana cara mengajukan tiket baru?',
        'answer': 'Dari Dashboard atau menu Tiket, tekan tombol tambah (+) di bagian tengah bawah layar, pilih layanan, dan isi detail kendala Anda.'
      },
      {
        'question': 'Apakah saya bisa melampirkan foto/dokumen pada tiket?',
        'answer': 'Tentu. Pada langkah pengisian detail tiket (Step 3), terdapat form untuk mengunggah file gambar atau dokumen.'
      },
      {
        'question': 'Kenapa halaman terus berputar (loading)?',
        'answer': 'Pastikan koneksi internet Anda stabil. Jika masih berlanjut, Anda dapat mencoba menutup aplikasi dan membukanya kembali (restart).'
      },
    ],
    'Akun': [
      {
        'question': 'Bagaimana cara mereset password?',
        'answer': 'Anda dapat mereset password dengan menekan opsi "Lupa Kata Sandi" di halaman Login, lalu masukkan email terdaftar Anda.'
      },
      {
        'question': 'Bagaimana cara mengganti data profil saya?',
        'answer': 'Buka menu "Profil" di kanan bawah, lalu pilih "Edit Profil". Anda dapat memperbarui nama, email, hingga nomor WhatsApp di sana.'
      },
      {
        'question': 'Siapa yang dapat saya hubungi jika akun terkunci?',
        'answer': 'Silakan hubungi Administrator IT atau atasan Anda melalui kontak darurat perusahaan untuk mereset status akun Anda.'
      },
    ]
  };

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: _faqData.keys.length,
      child: Scaffold(
        extendBodyBehindAppBar: true,
        appBar: AppBar(
          backgroundColor: Colors.transparent,
          elevation: 0,
          title: const Text(
            'Pusat Bantuan', 
            style: TextStyle(color: AppTheme.oceanWater, fontWeight: FontWeight.bold),
          ),
          centerTitle: true,
          leading: IconButton(
            icon: const Icon(Icons.arrow_back, color: AppTheme.oceanWater),
            onPressed: () => context.pop(),
          ),
          bottom: TabBar(
            isScrollable: false,
            indicatorColor: AppTheme.oceanWater,
            labelColor: AppTheme.oceanWater,
            unselectedLabelColor: Colors.grey.shade500,
            labelStyle: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
            tabs: _faqData.keys.map((String category) => Tab(text: category)).toList(),
          ),
        ),
        body: Container(
          height: double.infinity,
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                Color(0xFFE0F4FE),
                Color(0xFFF6FAFF),
              ],
            ),
          ),
          child: Stack(
            children: [
              Positioned(
                top: -100,
                right: -100,
                child: _buildBlob(
                  gradient: const LinearGradient(
                    colors: [
                      Color(0x1EFEA520),
                      Color(0x1A00A2E8),
                    ],
                  ),
                ),
              ),
              SafeArea(
                bottom: false,
                child: TabBarView(
                  children: _faqData.keys.map((String category) {
                    final faqs = _faqData[category]!;
                    return ListView.separated(
                      padding: const EdgeInsets.only(left: 24, right: 24, top: 24, bottom: 48),
                      itemCount: faqs.length,
                      separatorBuilder: (context, index) => const SizedBox(height: 12),
                      itemBuilder: (context, index) {
                        return _buildFaqItem(
                          faqs[index]['question']!,
                          faqs[index]['answer']!,
                        );
                      },
                    );
                  }).toList(),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildFaqItem(String question, String answer) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.7),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: AppTheme.oceanWater.withValues(alpha: 0.08),
            ),
          ),
          child: ExpansionTile(
            title: Text(
              question,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
            ),
            iconColor: AppTheme.oceanWater,
            collapsedIconColor: Colors.grey,
            children: [
              Padding(
                padding: const EdgeInsets.only(left: 16.0, right: 16.0, bottom: 16.0),
                child: Text(
                  answer,
                  style: TextStyle(fontSize: 13, color: Colors.blueGrey.shade700, height: 1.5),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBlob({required Gradient gradient}) {
    return ImageFiltered(
      imageFilter: ImageFilter.blur(sigmaX: 80, sigmaY: 80),
      child: Container(
        width: 300,
        height: 300,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: gradient,
        ),
      ),
    );
  }
}
