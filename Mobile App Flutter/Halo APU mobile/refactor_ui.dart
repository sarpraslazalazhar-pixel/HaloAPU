import 'dart:io';

void refactorScreen(String filePath) {
  var file = File(filePath);
  var content = file.readAsStringSync();

  // 1. change List<File> to List<XFile>
  content = content.replaceAll("List<File> _replyAttachments", "List<XFile> _replyAttachments");
  
  // 2. update _pickReplyAttachment
  var oldPick = '''  Future<void> _pickReplyAttachment(String type) async {
    Navigator.pop(context); // Close bottom sheet
    if (_replyAttachments.length >= 3) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Maksimal 3 lampiran!')));
      return;
    }

    Future<void> process(String path) async {
      final f = File(path);
      if (f.lengthSync() > 3 * 1024 * 1024) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ukuran file maksimal 3MB!')));
        return;
      }
      setState(() => _replyAttachments.add(f));
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
        allowMultiple: false,
      );
      if (result != null && result.files.single.path != null) {
        await process(result.files.single.path!);
      }
    }
  }''';

  var newPick = '''  Future<void> _pickReplyAttachment(String type) async {
    Navigator.pop(context); // Close bottom sheet
    if (_replyAttachments.length >= 3) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Maksimal 3 lampiran!')));
      return;
    }

    Future<void> process(XFile f) async {
      final len = await f.length();
      if (len > 3 * 1024 * 1024) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Ukuran file maksimal 3MB!')));
        return;
      }
      setState(() => _replyAttachments.add(f));
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
        allowMultiple: false,
        withData: true,
      );
      if (result != null) {
        final platformFile = result.files.single;
        if (platformFile.bytes != null) {
          await process(XFile.fromData(platformFile.bytes!, name: platformFile.name));
        } else if (platformFile.path != null) {
          await process(XFile(platformFile.path!));
        }
      }
    }
  }''';

  content = content.replaceFirst(oldPick, newPick);
  
  // 3. fix bottom action area
  content = content.replaceAll("file.path.split('/').last", "file.name");
  
  file.writeAsStringSync(content);
}

void main() {
  refactorScreen('lib/presentation/tickets/user_ticket_detail_screen.dart');
  refactorScreen('lib/presentation/tickets/admin_ticket_detail_screen.dart');
  // ignore: avoid_print
  print('Refactored UI screens');
}
