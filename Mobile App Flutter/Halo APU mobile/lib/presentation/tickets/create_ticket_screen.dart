import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:lottie/lottie.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:halo_apu_mobile/core/services/ticket_service.dart';
import 'package:halo_apu_mobile/core/services/pending_ticket_service.dart';
import 'package:halo_apu_mobile/core/providers/connectivity_provider.dart';
import 'package:halo_apu_mobile/core/theme/app_theme.dart';
import 'package:halo_apu_mobile/presentation/widgets/shimmer.dart';
import 'package:halo_apu_mobile/presentation/widgets/offline_banner.dart';
import 'providers/user_ticket_provider.dart';
import 'package:halo_apu_mobile/domain/models/ticket_model.dart';

class CreateTicketScreen extends ConsumerStatefulWidget {
  const CreateTicketScreen({super.key});

  @override
  ConsumerState<CreateTicketScreen> createState() => _CreateTicketScreenState();
}

class _CreateTicketScreenState extends ConsumerState<CreateTicketScreen> {
  final PageController _pageController = PageController();
  final TicketService _ticketService = TicketService();
  int _currentStep = 0;
  final int _totalSteps = 4;

  // Step 1: Service selection
  List<Map<String, dynamic>> _units = [];
  bool _isLoadingUnits = true;
  int? _selectedUnitIndex;
  int? _selectedSubUnitIndex;

  // Step 2 & 3: Dynamic form
  List<Map<String, dynamic>> _formFields = [];
  List<Map<String, dynamic>> _uploadFields = [];
  final Map<String, dynamic> _formData = {};
  final Map<String, List<XFile>> _attachmentFiles = {};
  bool _isLoadingFields = false;

  // Submission state
  bool _isSubmitting = false;

  final List<String> _stepTitles = [
    'Pilih Layanan',
    'Isi Detail',
    'Lampiran',
    'Ulasan & Kirim'
  ];

  @override
  void initState() {
    super.initState();
    _loadDraftAndServices();
  }

  Future<void> _loadDraftAndServices() async {
    await _loadServices();
    _loadDraft();
  }

  void _loadDraft() {
    final box = Hive.box('ticket_drafts');
    if (box.isNotEmpty) {
      setState(() {
        _selectedUnitIndex = box.get('selectedUnitIndex');
        _selectedSubUnitIndex = box.get('selectedSubUnitIndex');
        
        final draftForm = box.get('formData');
        if (draftForm != null) {
          final Map<String, dynamic> mapped = Map<String, dynamic>.from(draftForm);
          _formData.addAll(mapped);
        }

        final draftFiles = box.get('attachmentFiles');
        if (draftFiles != null) {
          final Map<String, dynamic> mappedFiles = Map<String, dynamic>.from(draftFiles);
          mappedFiles.forEach((key, paths) {
            final validFiles = (paths as List).map((p) => XFile(p.toString())).toList();
            if (validFiles.isNotEmpty) {
              _attachmentFiles[key] = validFiles;
            }
          });
        }
      });
      
      if (_selectedUnitIndex != null && _selectedSubUnitIndex != null && _units.isNotEmpty) {
        final subUnit = _units[_selectedUnitIndex!]['sub_units'][_selectedSubUnitIndex!];
        _loadFormFields(subUnit['id']);
      }
    }
  }

  void _saveDraft() {
    final box = Hive.box('ticket_drafts');
    box.put('selectedUnitIndex', _selectedUnitIndex);
    box.put('selectedSubUnitIndex', _selectedSubUnitIndex);
    box.put('formData', _formData);
    
    // Save file paths
    final Map<String, List<String>> filePaths = {};
    _attachmentFiles.forEach((key, files) {
      filePaths[key] = files.map((f) => f.path).toList();
    });
    box.put('attachmentFiles', filePaths);
  }

  Future<void> _loadServices() async {
    final result = await _ticketService.getServices();
    if (mounted) {
      setState(() {
        _isLoadingUnits = false;
        if (result['success']) {
          final List<dynamic> data = result['data'];
          _units = data.map<Map<String, dynamic>>((u) => {
            'id': u['id'],
            'nama': u['nama'] ?? u['nama_unit'] ?? 'Unit',
            'deskripsi': u['deskripsi'] ?? '',
            'sub_units': (u['sub_units'] as List<dynamic>?)?.map<Map<String, dynamic>>((s) => {
              'id': s['id'],
              'nama_layanan': s['nama_layanan'] ?? 'Layanan',
              'deskripsi': s['deskripsi'] ?? '',
            }).toList() ?? [],
          }).toList();
        }
      });
    }
  }

  Future<void> _loadFormFields(int subUnitId) async {
    setState(() {
      _isLoadingFields = true;
      _formFields = [];
      _uploadFields = [];
      _formData.clear();
      _attachmentFiles.clear();
    });

    final result = await _ticketService.getFormFields(subUnitId);
    if (mounted) {
      setState(() {
        _isLoadingFields = false;
        if (result['success']) {
          final List<dynamic> data = result['data'];
          for (var field in data) {
            final Map<String, dynamic> mapped = {
              'id': field['id'].toString(),
              'label': field['label'] ?? '',
              'type': field['tipe_field'] ?? 'teks_pendek',
              'required': field['wajib'] == true || field['wajib'] == 1,
              'options': field['opsi'] is List ? List<String>.from(field['opsi']) : <String>[],
              'parent_field_id': field['parent_field_id']?.toString(),
              'trigger_value': field['trigger_value'],
            };

            if (mapped['type'] == 'upload_gambar' || mapped['type'] == 'upload_file') {
              _uploadFields.add(mapped);
            } else {
              _formFields.add(mapped);
            }
          }
        }
      });
    }
  }

  bool _isFieldVisible(Map<String, dynamic> field) {
    if (field['parent_field_id'] != null && field['parent_field_id'] != '') {
      final parentValue = _formData[field['parent_field_id']];
      final triggerValue = field['trigger_value'];
      if (triggerValue is bool) {
        return parentValue == triggerValue;
      } else if (triggerValue is String) {
        return parentValue?.toString() == triggerValue;
      }
      return parentValue != null && parentValue != '' && parentValue != false;
    }
    return true;
  }

  void _nextStep() {
    if (_currentStep == 0) {
      if (_selectedUnitIndex == null) {
        _showError('Silakan pilih Kanal Layanan terlebih dahulu!');
        return;
      }
      if (_selectedSubUnitIndex == null) {
        _showError('Silakan pilih Jenis Layanan terlebih dahulu!');
        return;
      }
      // Load form fields for the selected sub unit
      final subUnit = _units[_selectedUnitIndex!]['sub_units'][_selectedSubUnitIndex!];
      _loadFormFields(subUnit['id']);
    }

    if (_currentStep == 1) {
      for (var field in _formFields) {
        if (field['required'] == true && _isFieldVisible(field)) {
          final val = _formData[field['id']];
          if (val == null || val == '' || (val is List && val.isEmpty)) {
            _showError('${field['label']} wajib diisi!');
            return;
          }
        }
      }
    }

    if (_currentStep == 2) {
      for (var field in _uploadFields) {
        if (field['required'] == true && _isFieldVisible(field)) {
          if (_attachmentFiles[field['id']] == null || _attachmentFiles[field['id']]!.isEmpty) {
            _showError('${field['label']} wajib diunggah!');
            return;
          }
        }
      }
    }

    if (_currentStep < _totalSteps - 1) {
      _pageController.nextPage(duration: const Duration(milliseconds: 300), curve: Curves.easeInOut);
    } else {
      _submitTicket();
    }
  }

  void _prevStep() {
    if (_currentStep > 0) {
      _pageController.previousPage(duration: const Duration(milliseconds: 300), curve: Curves.easeInOut);
    } else {
      Navigator.pop(context);
    }
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), backgroundColor: Colors.red.shade600),
    );
  }

  Future<void> _submitTicket() async {
    if (_isSubmitting) return;
    setState(() => _isSubmitting = true);

    final payload = _buildTicketPayload();

    // Offline: simpan ke antrian, kirim otomatis saat online
    if (!ref.read(connectivityProvider)) {
      await _queueTicket(payload);
      if (mounted) {
        setState(() => _isSubmitting = false);
        _finishToQueue();
      }
      return;
    }

    final result = await _ticketService.createTicket(
      subUnitId: payload['subUnitId'] as int,
      formData: (payload['formData'] as Map).cast<String, dynamic>(),
      priority: payload['priority'] as String,
      attachments: _attachmentFiles.isNotEmpty ? _attachmentFiles : null,
    );

    if (mounted) {
      setState(() => _isSubmitting = false);

      if (result['success']) {
        // Add the new ticket to the provider
        if (result['data'] != null) {
          try {
            final newTicket = TicketModel.safeFromJson(result['data']);
            ref.read(userTicketProvider.notifier).addTicket(newTicket);
          } catch (_) {
            // If parsing fails, just refresh the list
            ref.read(userTicketProvider.notifier).refresh();
          }
        } else {
          ref.read(userTicketProvider.notifier).refresh();
        }

        if (mounted) {
          Hive.box('ticket_drafts').clear();
          showDialog(
            context: context,
            barrierDismissible: false,
            builder: (ctx) => Dialog(
              backgroundColor: Colors.transparent,
              elevation: 0,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 150, height: 150,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
                    child: Center(
                      child: Lottie.network(
                        'https://lottie.host/80e7747e-4078-4a9f-a2e6-1215b02130dc/U6EaQ9YFqQ.json',
                        repeat: false,
                        errorBuilder: (context, error, stack) => const Icon(Icons.check_circle, color: Colors.green, size: 80),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          );
          
          Future.delayed(const Duration(seconds: 2), () {
            if (mounted) {
              Navigator.pop(context); // close dialog
              context.pop(); // close screen
            }
          });
        }
      } else {
        final message = result['message']?.toString() ?? 'Gagal membuat tiket';
        if (message.startsWith('Kesalahan: ')) {
          // Koneksi putus saat pengiriman -> antri & kirim otomatis nanti
          await _queueTicket(payload);
          if (mounted) _finishToQueue();
        } else {
          _showError(message);
        }
      }
    }
  }

  Map<String, dynamic> _buildTicketPayload() {
    final subUnit = _units[_selectedUnitIndex!]['sub_units'][_selectedSubUnitIndex!];

    final Map<String, dynamic> formDataPayload = {};
    for (var field in _formFields) {
      if (_formData.containsKey(field['id'])) {
        formDataPayload[field['id']] = _formData[field['id']];
      }
    }

    String priority = 'normal';
    for (var field in _formFields) {
      final val = _formData[field['id']];
      if (val is String) {
        final lower = val.toLowerCase();
        if (lower == 'mendesak' || lower == 'urgent' || lower == 'tinggi' || lower == 'high') {
          priority = 'high';
        } else if (lower == 'rendah' || lower == 'low') {
          priority = 'low';
        }
      }
    }

    final attachmentFiles = <String, List<String>>{};
    _attachmentFiles.forEach((fieldId, files) {
      attachmentFiles[fieldId] = files.map((f) => f.path).toList();
    });

    return {
      'subUnitId': subUnit['id'],
      'formData': formDataPayload,
      'priority': priority,
      'attachmentFiles': attachmentFiles,
    };
  }

  Future<void> _queueTicket(Map<String, dynamic> payload) async {
    await PendingTicketService().enqueue(payload);
  }

  void _finishToQueue() {
    Hive.box('ticket_drafts').clear();
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: const Text('Tiket Tersimpan di Antrian'),
        content: const Text(
          'Koneksi sedang tidak tersedia. Tiket akan dikirim otomatis saat online kembali.',
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              if (mounted) context.pop();
            },
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  Future<void> _pickFile(String fieldId, String type) async {
    final currentFiles = _attachmentFiles[fieldId] ?? [];
    if (currentFiles.length >= 3) {
      _showError('Maksimal 3 file yang diizinkan!');
      return;
    }

    Future<void> processFile(XFile file) async {
      final len = await file.length();
      if (len > 3 * 1024 * 1024) {
        _showError('Ukuran file maksimal 3MB!');
        return;
      }
      setState(() {
        _attachmentFiles[fieldId] = [...(_attachmentFiles[fieldId] ?? []), file];
        _saveDraft();
      });
    }

    if (type == 'upload_gambar') {
      final source = await showModalBottomSheet<ImageSource>(
        context: context,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        builder: (ctx) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 40, height: 4,
                  margin: const EdgeInsets.only(bottom: 20),
                  decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2)),
                ),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: const Color(0xFFE0F2FE), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.camera_alt, color: Color(0xFF0284C7)),
                  ),
                  title: const Text('Kamera', style: TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: const Text('Ambil foto langsung', style: TextStyle(fontSize: 12)),
                  onTap: () => Navigator.pop(ctx, ImageSource.camera),
                ),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: const Color(0xFFFCE7F3), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.photo_library, color: Color(0xFFDB2777)),
                  ),
                  title: const Text('Galeri Foto', style: TextStyle(fontWeight: FontWeight.bold)),
                  subtitle: const Text('Pilih foto dari perangkat', style: TextStyle(fontSize: 12)),
                  onTap: () => Navigator.pop(ctx, ImageSource.gallery),
                ),
              ],
            ),
          ),
        ),
      );

      if (source != null) {
        final picker = ImagePicker();
        final XFile? image = await picker.pickImage(source: source, maxWidth: 1920, imageQuality: 80);
        if (image != null) {
          await processFile(image);
        }
      }
    } else {
      // upload_file (documents)
      final result = await FilePicker.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
        allowMultiple: false,
        withData: true,
      );
      
      if (result != null) {
        final pf = result.files.single;
        if (pf.bytes != null) {
          await processFile(XFile.fromData(pf.bytes!, name: pf.name));
        } else if (pf.path != null) {
          await processFile(XFile(pf.path!));
        }
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        title: Text(_stepTitles[_currentStep], style: const TextStyle(fontWeight: FontWeight.bold)),
        elevation: 0,
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
        leading: IconButton(
          icon: Icon(_currentStep == 0 ? Icons.close : Icons.arrow_back),
          onPressed: _prevStep,
        ),
      ),
      body: Column(
        children: [
          const OfflineBanner(),

          // Glassmorphism Stepper Indicator
          ClipRRect(
            child: BackdropFilter(
              filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.6),
                  border: Border(bottom: BorderSide(color: Colors.grey.shade200)),
                ),
                child: Row(
                  children: List.generate(_totalSteps, (index) {
                    return Expanded(
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 300),
                        margin: EdgeInsets.only(right: index == _totalSteps - 1 ? 0 : 8),
                        height: 4,
                        decoration: BoxDecoration(
                          color: index <= _currentStep ? AppTheme.oceanWater : Colors.grey.shade300,
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                    );
                  }),
                ),
              ),
            ),
          ),

          // Pages
          Expanded(
            child: PageView(
              controller: _pageController,
              physics: const NeverScrollableScrollPhysics(),
              onPageChanged: (index) {
                setState(() => _currentStep = index);
              },
              children: [
                _buildStep1ChooseService(),
                _buildStep2Form(),
                _buildStep3Attachment(),
                _buildStep4Review(),
              ],
            ),
          ),

          // Bottom Navigation
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: Colors.white,
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.05),
                  blurRadius: 10,
                  offset: const Offset(0, -5),
                ),
              ],
            ),
            child: SafeArea(
              child: SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: _isSubmitting ? null : _nextStep,
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    backgroundColor: AppTheme.brilliantBlue,
                    foregroundColor: Colors.white,
                    elevation: 0,
                  ),
                  child: _isSubmitting
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                      : Text(
                          _currentStep == _totalSteps - 1 ? 'Kirim Tiket' : 'Langkah Selanjutnya',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                        ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ============ STEP 1: Choose Service ============
  Widget _buildStep1ChooseService() {
    if (_isLoadingUnits) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ShimmerBox(height: 24, width: 150),
            SizedBox(height: 12),
            ShimmerBox(height: 80, width: double.infinity, radius: 16),
            SizedBox(height: 12),
            ShimmerBox(height: 80, width: double.infinity, radius: 16),
            SizedBox(height: 12),
            ShimmerBox(height: 80, width: double.infinity, radius: 16),
          ],
        ),
      );
    }

    if (_units.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.inbox_outlined, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 16),
            Text('Tidak ada layanan tersedia', style: TextStyle(color: Colors.grey.shade600)),
            const SizedBox(height: 16),
            TextButton.icon(
              onPressed: () {
                setState(() => _isLoadingUnits = true);
                _loadServices();
              },
              icon: const Icon(Icons.refresh),
              label: const Text('Coba Lagi'),
            ),
          ],
        ),
      );
    }

    final currentSubUnits = _selectedUnitIndex != null
        ? (_units[_selectedUnitIndex!]['sub_units'] as List<Map<String, dynamic>>)
        : <Map<String, dynamic>>[];

    final unitIcons = [
      Icons.computer, Icons.business, Icons.people, Icons.build,
      Icons.local_shipping, Icons.school, Icons.settings, Icons.security,
    ];

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Kanal Layanan', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 12),
          ...List.generate(_units.length, (index) {
            final unit = _units[index];
            final isSelected = _selectedUnitIndex == index;
            final icon = unitIcons[index % unitIcons.length];

            return Card(
              elevation: isSelected ? 4 : 0,
              margin: const EdgeInsets.only(bottom: 12),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: BorderSide(
                  color: isSelected ? AppTheme.oceanWater : Colors.grey.shade200,
                  width: isSelected ? 2 : 1,
                ),
              ),
              child: InkWell(
                onTap: () {
                  setState(() {
                    _selectedUnitIndex = index;
                    _selectedSubUnitIndex = null;
                    _saveDraft();
                  });
                },
                borderRadius: BorderRadius.circular(16),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: isSelected ? AppTheme.oceanWater : AppTheme.oceanWater.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Icon(icon, color: isSelected ? Colors.white : AppTheme.oceanWater),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              unit['nama'] as String,
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                                color: isSelected ? AppTheme.oceanWater : Colors.black87,
                              ),
                            ),
                            if ((unit['deskripsi'] as String).isNotEmpty) ...[
                              const SizedBox(height: 4),
                              Text(unit['deskripsi'] as String, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
                            ],
                          ],
                        ),
                      ),
                      Icon(
                        isSelected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                        color: isSelected ? AppTheme.oceanWater : Colors.grey.shade400,
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),

          if (_selectedUnitIndex != null) ...[
            const SizedBox(height: 24),
            const Text('Jenis Layanan', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            const SizedBox(height: 12),
            if (currentSubUnits.isEmpty)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text('Tidak ada sub layanan untuk kanal ini', style: TextStyle(color: Colors.grey.shade500)),
              )
            else
              ...List.generate(currentSubUnits.length, (index) {
                final subUnit = currentSubUnits[index];
                final isSelected = _selectedSubUnitIndex == index;
                return Card(
                  elevation: 0,
                  margin: const EdgeInsets.only(bottom: 8),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                    side: BorderSide(
                      color: isSelected ? AppTheme.oceanWater : Colors.grey.shade200,
                      width: isSelected ? 2 : 1,
                    ),
                  ),
                  child: ListTile(
                    onTap: () => setState(() { _selectedSubUnitIndex = index; _saveDraft(); }),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    title: Text(
                      subUnit['nama_layanan'] as String,
                      style: TextStyle(
                        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                        color: isSelected ? AppTheme.oceanWater : Colors.black87,
                      ),
                    ),
                    subtitle: (subUnit['deskripsi'] as String).isNotEmpty
                        ? Text(subUnit['deskripsi'] as String, style: const TextStyle(fontSize: 12))
                        : null,
                    trailing: Icon(
                      isSelected ? Icons.check_circle : Icons.circle_outlined,
                      color: isSelected ? AppTheme.oceanWater : Colors.grey.shade300,
                    ),
                  ),
                );
              }),
          ],
        ],
      ),
    );
  }

  // ============ STEP 2: Dynamic Form ============
  Widget _buildStep2Form() {
    if (_isLoadingFields) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ShimmerBox(height: 20, width: 120),
            SizedBox(height: 8),
            ShimmerBox(height: 50, width: double.infinity, radius: 16),
            SizedBox(height: 24),
            ShimmerBox(height: 20, width: 150),
            SizedBox(height: 8),
            ShimmerBox(height: 50, width: double.infinity, radius: 16),
          ],
        ),
      );
    }

    if (_formFields.isEmpty) {
      return const Center(
        child: Text('Tidak ada field formulir untuk layanan ini.', style: TextStyle(color: Colors.grey)),
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: AnimatedSwitcher(
        duration: const Duration(milliseconds: 300),
        child: Column(
          key: ValueKey(_formFields.length),
          crossAxisAlignment: CrossAxisAlignment.start,
          children: _formFields.where((f) => _isFieldVisible(f)).map((field) {
            return Padding(
              padding: const EdgeInsets.only(bottom: 24),
              child: _buildDynamicField(field),
            );
          }).toList(),
        ),
      ),
    );
  }

  Widget _buildDynamicField(Map<String, dynamic> field) {
    final String id = field['id'];
    final String label = field['label'];
    final bool isRequired = field['required'] == true;
    final String type = field['type'];
    final List<String> options = field['options'] ?? [];

    Widget inputWidget;

    switch (type) {
      case 'dropdown':
      case 'radio':
        inputWidget = DropdownButtonFormField<String>(
          initialValue: _formData[id] is String ? _formData[id] : null,
          decoration: _inputDecoration(),
          hint: const Text('Pilih opsi...'),
          items: options.map((opt) => DropdownMenuItem(value: opt, child: Text(opt))).toList(),
          onChanged: (val) { setState(() { _formData[id] = val; _saveDraft(); }); },
        );
        break;
      case 'checkbox':
        inputWidget = CheckboxListTile(
          title: Text('Ya, ${label.toLowerCase()}', style: const TextStyle(fontSize: 14)),
          value: _formData[id] ?? false,
          onChanged: (val) { setState(() { _formData[id] = val; _saveDraft(); }); },
          controlAffinity: ListTileControlAffinity.leading,
          contentPadding: EdgeInsets.zero,
          dense: true,
        );
        break;
      case 'multi_pilih':
        final selectedList = (_formData[id] as List<String>?) ?? [];
        inputWidget = Column(
          children: options.map((opt) {
            return CheckboxListTile(
              title: Text(opt, style: const TextStyle(fontSize: 14)),
              value: selectedList.contains(opt),
              onChanged: (val) {
                setState(() {
                  if (val == true) {
                    _formData[id] = [...selectedList, opt];
                  } else {
                    _formData[id] = selectedList.where((e) => e != opt).toList();
                  }
                  _saveDraft();
                });
              },
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              dense: true,
            );
          }).toList(),
        );
        break;
      case 'nominal_rp':
      case 'angka':
        inputWidget = TextField(
          keyboardType: TextInputType.number,
          decoration: _inputDecoration().copyWith(
            prefixText: type == 'nominal_rp' ? 'Rp ' : null,
            hintText: 'Ketik angka...',
          ),
          onChanged: (val) { _formData[id] = val; _saveDraft(); },
        );
        break;
      case 'tanggal':
        inputWidget = InkWell(
          onTap: () async {
            final date = await showDatePicker(
              context: context,
              initialDate: DateTime.now(),
              firstDate: DateTime(2020),
              lastDate: DateTime(2030),
            );
            if (date != null) {
              setState(() { _formData[id] = '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}'; _saveDraft(); });
            }
          },
          child: InputDecorator(
            decoration: _inputDecoration().copyWith(
              suffixIcon: const Icon(Icons.calendar_today, size: 18),
            ),
            child: Text(
              _formData[id] ?? 'Pilih tanggal...',
              style: TextStyle(color: _formData[id] != null ? Colors.black87 : Colors.grey.shade400),
            ),
          ),
        );
        break;
      case 'waktu':
        inputWidget = InkWell(
          onTap: () async {
            final time = await showTimePicker(context: context, initialTime: TimeOfDay.now());
            if (time != null) {
              setState(() { _formData[id] = '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}'; _saveDraft(); });
            }
          },
          child: InputDecorator(
            decoration: _inputDecoration().copyWith(
              suffixIcon: const Icon(Icons.access_time, size: 18),
            ),
            child: Text(
              _formData[id] ?? 'Pilih waktu...',
              style: TextStyle(color: _formData[id] != null ? Colors.black87 : Colors.grey.shade400),
            ),
          ),
        );
        break;
      case 'datetime':
        inputWidget = InkWell(
          onTap: () async {
            final date = await showDatePicker(context: context, initialDate: DateTime.now(), firstDate: DateTime(2020), lastDate: DateTime(2030));
            if (date != null && mounted) {
              final time = await showTimePicker(context: context, initialTime: TimeOfDay.now());
              if (time != null) {
                setState(() { _formData[id] = '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')} ${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}'; _saveDraft(); });
              }
            }
          },
          child: InputDecorator(
            decoration: _inputDecoration().copyWith(
              suffixIcon: const Icon(Icons.event, size: 18),
            ),
            child: Text(
              _formData[id] ?? 'Pilih tanggal & waktu...',
              style: TextStyle(color: _formData[id] != null ? Colors.black87 : Colors.grey.shade400),
            ),
          ),
        );
        break;
      case 'info_peraturan':
        inputWidget = Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.blue.shade50,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: Colors.blue.shade200),
          ),
          child: Row(
            children: [
              Icon(Icons.info_outline, color: Colors.blue.shade700, size: 20),
              const SizedBox(width: 12),
              Expanded(child: Text(label, style: TextStyle(fontSize: 13, color: Colors.blue.shade700))),
            ],
          ),
        );
        break;
      case 'teks_panjang':
      case 'textarea':
        inputWidget = TextField(
          maxLines: 4,
          decoration: _inputDecoration().copyWith(hintText: 'Ketik di sini...'),
          onChanged: (val) { _formData[id] = val; _saveDraft(); },
        );
        break;
      case 'teks_pendek':
      case 'text':
      default:
        inputWidget = TextField(
          maxLines: 1,
          decoration: _inputDecoration().copyWith(hintText: 'Ketik di sini...'),
          onChanged: (val) { _formData[id] = val; _saveDraft(); },
        );
    }

    if (type == 'info_peraturan') {
      return inputWidget;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
            if (isRequired) const Text(' *', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
          ],
        ),
        if (type != 'checkbox') const SizedBox(height: 8),
        inputWidget,
      ],
    );
  }

  InputDecoration _inputDecoration() {
    return InputDecoration(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: Colors.grey.shade300)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: Colors.grey.shade300)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: AppTheme.oceanWater, width: 2)),
      isDense: true,
    );
  }

  // ============ STEP 3: Attachment ============
  Widget _buildStep3Attachment() {
    final visibleUploads = _uploadFields.where((f) => _isFieldVisible(f)).toList();

    if (visibleUploads.isEmpty) {
      return const Center(child: Text('Tidak ada lampiran yang dibutuhkan untuk layanan ini.', style: TextStyle(color: Colors.grey)));
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: visibleUploads.map((field) {
          final String id = field['id'];
          final String label = field['label'];
          final bool isRequired = field['required'] == true;
          final String type = field['type'];
          final List<XFile> currentFiles = _attachmentFiles[id] ?? [];

          return Padding(
            padding: const EdgeInsets.only(bottom: 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
                    if (isRequired) const Text(' *', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  type == 'upload_gambar'
                      ? 'Format: JPG, PNG (Maks. 3MB)'
                      : 'Format: JPG, PNG, PDF (Maks. 3MB)',
                  style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
                ),
                const SizedBox(height: 12),

                // Upload Box
                InkWell(
                  onTap: () => _pickFile(id, type),
                  borderRadius: BorderRadius.circular(16),
                  child: Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    decoration: BoxDecoration(
                      border: Border.all(color: AppTheme.oceanWater),
                      borderRadius: BorderRadius.circular(16),
                      color: AppTheme.oceanWater.withValues(alpha: 0.05),
                    ),
                    child: Column(
                      children: [
                        Icon(
                          type == 'upload_gambar' ? Icons.camera_alt : Icons.cloud_upload_outlined,
                          size: 32,
                          color: AppTheme.oceanWater,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          type == 'upload_gambar' ? 'Ambil foto atau pilih dari galeri' : 'Ketuk untuk mencari file',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
                ),

                // File List
                if (currentFiles.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Column(
                      children: currentFiles.asMap().entries.map((entry) {
                        final file = entry.value;
                        final fileName = file.name;
                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade50,
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: Colors.grey.shade200),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.insert_drive_file, color: Colors.blueGrey),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Text(
                                  fileName,
                                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete, color: Colors.red, size: 18),
                                padding: EdgeInsets.zero,
                                constraints: const BoxConstraints(),
                                onPressed: () {
                                  setState(() {
                                    _attachmentFiles[id]!.removeAt(entry.key);
                                    _saveDraft();
                                  });
                                },
                              ),
                            ],
                          ),
                        );
                      }).toList(),
                    ),
                  ),
              ],
            ),
          );
        }).toList(),
      ),
    );
  }

  // ============ STEP 4: Review & Submit ============
  Widget _buildStep4Review() {
    final currentUnit = _selectedUnitIndex != null ? _units[_selectedUnitIndex!] : null;
    final currentSubUnit = (_selectedUnitIndex != null && _selectedSubUnitIndex != null)
        ? _units[_selectedUnitIndex!]['sub_units'][_selectedSubUnitIndex!]
        : null;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _buildReviewSection(
            title: 'Pilihan Layanan',
            icon: Icons.support_agent,
            children: [
              _buildReviewRow('Kanal Layanan', currentUnit?['nama'] as String? ?? '-'),
              const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Divider(height: 1)),
              _buildReviewRow('Jenis Layanan', currentSubUnit?['nama_layanan'] as String? ?? '-'),
            ],
          ),
          const SizedBox(height: 16),

          if (_formFields.where((f) => _isFieldVisible(f)).isNotEmpty)
            _buildReviewSection(
              title: 'Isian Detail',
              icon: Icons.list_alt,
              children: _formFields.where((f) => _isFieldVisible(f)).map((field) {
                final val = _formData[field['id']];
                String displayVal = '-';
                if (field['type'] == 'checkbox') {
                  displayVal = val == true ? 'Ya' : 'Tidak';
                } else if (field['type'] == 'multi_pilih') {
                  displayVal = (val as List?)?.join(', ') ?? '-';
                } else if (field['type'] == 'nominal_rp') {
                  displayVal = val != null && val != '' ? 'Rp $val' : '-';
                } else {
                  displayVal = val?.toString() ?? '-';
                }

                return Column(
                  children: [
                    _buildReviewRow(field['label'], displayVal.isEmpty ? '-' : displayVal),
                    if (field != _formFields.where((f) => _isFieldVisible(f)).last)
                      const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Divider(height: 1)),
                  ],
                );
              }).toList(),
            ),
          const SizedBox(height: 16),

          if (_uploadFields.where((f) => _isFieldVisible(f)).isNotEmpty)
            _buildReviewSection(
              title: 'Lampiran',
              icon: Icons.attachment,
              children: _uploadFields.where((f) => _isFieldVisible(f)).map((field) {
                final files = _attachmentFiles[field['id']] ?? [];
                final fileNames = files.map((f) => f.path.split('/').last).join(', ');
                return Column(
                  children: [
                    _buildReviewRow(field['label'], files.isEmpty ? 'Belum diunggah' : '${files.length} file'),
                    if (fileNames.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Align(
                          alignment: Alignment.centerRight,
                          child: Text(fileNames, style: TextStyle(fontSize: 11, color: Colors.grey.shade500), textAlign: TextAlign.right),
                        ),
                      ),
                    if (field != _uploadFields.where((f) => _isFieldVisible(f)).last)
                      const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Divider(height: 1)),
                  ],
                );
              }).toList(),
            ),

          const SizedBox(height: 24),
          Row(
            children: [
              Icon(Icons.info_outline, size: 16, color: Colors.blue.shade700),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Pastikan semua data sudah benar sebelum mengirim tiket.',
                  style: TextStyle(fontSize: 12, color: Colors.blue.shade700, fontWeight: FontWeight.w500),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildReviewSection({required String title, required IconData icon, required List<Widget> children}) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(color: Colors.black.withValues(alpha: 0.02), blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: Colors.grey.shade50,
              borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
              border: Border(bottom: BorderSide(color: Colors.grey.shade200)),
            ),
            child: Row(
              children: [
                Icon(icon, size: 18, color: AppTheme.oceanWater),
                const SizedBox(width: 8),
                Text(title, style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.black87)),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
          ),
        ],
      ),
    );
  }

  Widget _buildReviewRow(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 2,
          child: Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
        ),
        const SizedBox(width: 12),
        Expanded(
          flex: 3,
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: TextStyle(
              fontWeight: FontWeight.w600,
              fontSize: 13,
              color: value == 'Belum diunggah' || value == '-' ? Colors.red.shade400 : Colors.black87,
            ),
          ),
        ),
      ],
    );
  }
}
