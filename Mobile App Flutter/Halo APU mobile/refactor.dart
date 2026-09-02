import 'dart:io';

void main() {
  var file = File('lib/core/services/ticket_service.dart');
  var content = file.readAsStringSync();
  
  // 1. replace dart:io with image_picker and foundation
  content = content.replaceFirst("import 'dart:io';", "import 'dart:io';\nimport 'package:flutter/foundation.dart';\nimport 'package:image_picker/image_picker.dart';");
  
  // 2. add _createMultipartFile helper
  var helperMethod = '''
  Future<http.MultipartFile> _createMultipartFile(String field, XFile file) async {
    if (kIsWeb) {
      final bytes = await file.readAsBytes();
      return http.MultipartFile.fromBytes(field, bytes, filename: file.name);
    } else {
      return await http.MultipartFile.fromPath(field, file.path);
    }
  }

  // ============ SERVICES ============
''';
  content = content.replaceFirst("// ============ SERVICES ============", helperMethod);
  
  // 3. replace Map<String, List<File>> with Map<String, List<XFile>>
  content = content.replaceAll("Map<String, List<File>>?", "Map<String, List<XFile>>?");
  
  // 4. replace List<File>? with List<XFile>?
  content = content.replaceAll("List<File>? attachments", "List<XFile>? attachments");
  
  // 5. replace http.MultipartFile.fromPath 1: createTicket
  content = content.replaceAll(
    "request.files.add(await http.MultipartFile.fromPath(\n              'attachments[\${entry.key}][]',\n              file.path,\n            ));", 
    "request.files.add(await _createMultipartFile('attachments[\${entry.key}][]', file));"
  );
  
  // 6. replace http.MultipartFile.fromPath 2: changeStatus
  content = content.replaceAll(
    "request.files.add(await http.MultipartFile.fromPath(\n            'general_attachments[]',\n            file.path,\n          ));",
    "request.files.add(await _createMultipartFile('general_attachments[]', file));"
  );
  
  // 7. replace http.MultipartFile.fromPath 3: replyTicket & requestRevision
  content = content.replaceAll(
    "request.files.add(await http.MultipartFile.fromPath('attachments[]', file.path));",
    "request.files.add(await _createMultipartFile('attachments[]', file));"
  );
  
  file.writeAsStringSync(content);
  // ignore: avoid_print
  print('Refactored TicketService');
}
