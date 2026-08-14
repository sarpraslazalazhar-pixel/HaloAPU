import 'dart:io';

void main() {
  var file = File('lib/presentation/tickets/widgets/chat_bubble.dart');
  var content = file.readAsStringSync();
  
  if (!content.contains("import 'package:url_launcher/url_launcher.dart';")) {
    content = content.replaceFirst(
      "import 'package:flutter/material.dart';", 
      "import 'package:flutter/material.dart';\nimport 'package:url_launcher/url_launcher.dart';"
    );
  }
  
  var oldImageBlock = '''        if (isImg) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: Image.network(
                url,
                height: 160,
                width: double.infinity,
                fit: BoxFit.cover,
                errorBuilder: (context, error, stackTrace) => Container(
                  height: 160,
                  color: isMe ? Colors.black12 : Colors.grey.shade200,
                  child: Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.broken_image, color: isMe ? Colors.white54 : Colors.grey),
                        const SizedBox(height: 4),
                        Text('Gagal memuat', style: TextStyle(fontSize: 10, color: isMe ? Colors.white54 : Colors.grey)),
                      ]
                    )
                  ),
                ),
              ),
            ),
          );
        } else {''';

  var newImageBlock = '''        if (isImg) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: InkWell(
              onTap: () async {
                final uri = Uri.parse(url);
                if (await canLaunchUrl(uri)) await launchUrl(uri);
              },
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  url,
                  height: 160,
                  width: double.infinity,
                  fit: BoxFit.cover,
                  errorBuilder: (context, error, stackTrace) => Container(
                    height: 160,
                    color: isMe ? Colors.black12 : Colors.grey.shade200,
                    child: Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.broken_image, color: isMe ? Colors.white54 : Colors.grey),
                          const SizedBox(height: 4),
                          Text('Gagal memuat', style: TextStyle(fontSize: 10, color: isMe ? Colors.white54 : Colors.grey)),
                        ]
                      )
                    ),
                  ),
                ),
              ),
            ),
          );
        } else {''';
        
  var oldFileBlock = '''          return Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: isMe ? Colors.black.withValues(alpha: 0.15) : Colors.grey.shade100,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: isMe ? Colors.transparent : Colors.grey.shade300),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.insert_drive_file, size: 20, color: isMe ? Colors.white : Colors.grey.shade700),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    fileName,
                    style: TextStyle(
                      fontSize: 12,
                      color: isMe ? Colors.white : Colors.black87,
                      decoration: TextDecoration.underline,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
          );''';
          
  var newFileBlock = '''          return InkWell(
            onTap: () async {
              final uri = Uri.parse(url);
              if (await canLaunchUrl(uri)) await launchUrl(uri);
            },
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: isMe ? Colors.black.withValues(alpha: 0.15) : Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: isMe ? Colors.transparent : Colors.grey.shade300),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.insert_drive_file, size: 20, color: isMe ? Colors.white : Colors.grey.shade700),
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(
                      fileName,
                      style: TextStyle(
                        fontSize: 12,
                        color: isMe ? Colors.white : Colors.black87,
                        decoration: TextDecoration.underline,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
          );''';
          
  content = content.replaceFirst(oldImageBlock, newImageBlock);
  content = content.replaceFirst(oldFileBlock, newFileBlock);
  
  file.writeAsStringSync(content);
  // ignore: avoid_print
  print('Refactored chat bubble');
}
