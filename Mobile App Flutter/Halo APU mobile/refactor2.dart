import 'dart:io';

void main() {
  var adminFile = File('lib/presentation/tickets/admin_ticket_detail_screen.dart');
  var content = adminFile.readAsStringSync();
  content = content.replaceAll("file.path.split('/').last", "file.name");
  content = content.replaceAll("List<File> _generalAttachments = [];", "List<XFile> _generalAttachments = [];");
  adminFile.writeAsStringSync(content);
  
  var createFile = File('lib/presentation/tickets/create_ticket_screen.dart');
  var createContent = createFile.readAsStringSync();
  createContent = createContent.replaceAll("Map<String, List<File>> _attachments = {};", "Map<String, List<XFile>> _attachments = {};");
  createContent = createContent.replaceAll("file.path.split('/').last", "file.name");
  
  var oldPick = '''  Future<void> _pickAttachment(String fieldId, String type) async {
    final maxFiles = 3;
    final currentFiles = _attachments[fieldId] ?? [];
    if (currentFiles.length >= maxFiles) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Maksimal \$maxFiles lampiran!')));
      return;
    }

    Future<void> process(String path) async {
      final f = File(path);
      if (f.lengthSync() > 3 * 1024 * 1024) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ukuran file maksimal 3MB!')));
        return;
      }
      setState(() {
        _attachments[fieldId] = [...currentFiles, f];
      });
    }

    if (type == 'kamera' || type == 'galeri') {
      final picker = ImagePicker();
      final source = type == 'kamera' ? ImageSource.camera : ImageSource.gallery;
      final image = await picker.pickImage(source: source, maxWidth: 1920, imageQuality: 80);
      if (image != null) await process(image.path);
    } else {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
        allowMultiple: true,
      );
      if (result != null) {
        int remaining = maxFiles - currentFiles.length;
        for (var file in result.files.take(remaining)) {
          if (file.path != null) await process(file.path!);
        }
      }
    }
  }''';
  
  var newPick = '''  Future<void> _pickAttachment(String fieldId, String type) async {
    final maxFiles = 3;
    final currentFiles = _attachments[fieldId] ?? [];
    if (currentFiles.length >= maxFiles) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Maksimal \$maxFiles lampiran!')));
      return;
    }

    Future<void> process(XFile f) async {
      final len = await f.length();
      if (len > 3 * 1024 * 1024) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ukuran file maksimal 3MB!')));
        return;
      }
      setState(() {
        _attachments[fieldId] = [...(_attachments[fieldId] ?? []), f];
      });
    }

    if (type == 'kamera' || type == 'galeri') {
      final picker = ImagePicker();
      final source = type == 'kamera' ? ImageSource.camera : ImageSource.gallery;
      final image = await picker.pickImage(source: source, maxWidth: 1920, imageQuality: 80);
      if (image != null) await process(image);
    } else {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
        allowMultiple: true,
        withData: true,
      );
      if (result != null) {
        int remaining = maxFiles - currentFiles.length;
        for (var file in result.files.take(remaining)) {
          if (file.bytes != null) {
            await process(XFile.fromData(file.bytes!, name: file.name));
          } else if (file.path != null) {
            await process(XFile(file.path!));
          }
        }
      }
    }
  }''';
  
  createContent = createContent.replaceFirst(oldPick, newPick);
  createFile.writeAsStringSync(createContent);
  
  // ignore: avoid_print
  print('done');
}
