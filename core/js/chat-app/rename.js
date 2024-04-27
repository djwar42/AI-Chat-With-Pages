const fs = require('fs')
const path = require('path')

// Function to rename files and remove unwanted files
function cleanDirectory(dir, newFileName) {
  const files = fs.readdirSync(dir)

  // Find the main bundle file and rename it
  const targetFile = files.find(
    (file) =>
      file.startsWith('main.') &&
      !file.endsWith('.map') &&
      !file.endsWith('.LICENSE.txt')
  )
  if (targetFile) {
    fs.renameSync(path.join(dir, targetFile), path.join(dir, newFileName))
  }

  // Delete source map files and txt files
  const filesToDelete = files.filter(
    (file) => file.endsWith('.map') || file.endsWith('.txt')
  )
  filesToDelete.forEach((file) => {
    fs.unlinkSync(path.join(dir, file))
  })
}

// Directories to process
const jsDir = path.resolve(__dirname, 'build', 'static', 'js')
const cssDir = path.resolve(__dirname, 'build', 'static', 'css')

// Rename and clean up JavaScript directory
cleanDirectory(jsDir, 'aichwp.js')

// Rename and clean up CSS directory (if necessary)
cleanDirectory(cssDir, 'aichwp.css')
